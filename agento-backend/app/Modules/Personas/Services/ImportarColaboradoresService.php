<?php

namespace App\Modules\Personas\Services;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Http\Requests\StoreColaboradorRequest;
use App\Modules\Personas\Infrastructure\ColaboradorXlsxReader;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Importador de colaboradores vía Excel — mismo patrón que
 * ImportarHorariosService: previsualizar() de solo lectura + importar() que
 * persiste, reutilizando ColaboradorService::crear() para no duplicar sus
 * reglas de negocio (unicidad de documento, generación de legajo, alta de
 * remuneración/horario/calendario en una sola transacción).
 *
 * A propósito, SOLO crea colaboradores nuevos — nunca actualiza uno
 * existente. Sobrescribir remuneración/datos de alguien ya activo por una
 * reimportación es demasiado riesgoso (podría pisar un cambio reciente sin
 * que nadie lo note); si el documento ya existe, la fila queda "con error".
 */
class ImportarColaboradoresService
{
    public function __construct(
        private readonly ColaboradorXlsxReader $lector,
        private readonly ColaboradorService $colaboradores,
    ) {}

    public function previsualizar(Empresa $empresa, UploadedFile $archivo): array
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        $evaluadas = $this->evaluarFilas($empresa, $filas);

        return [
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_tamano' => $archivo->getSize(),
            'filas_invalidas' => $this->lector->filasInvalidas(),
            'colaboradores_detectados' => count($evaluadas),
            'colaboradores' => array_map(fn ($fila) => [
                'nombre' => $fila['nombre'],
                'numero_documento' => $fila['numero_documento'],
                'accion' => $fila['accion'],
                'errores' => $fila['errores'],
            ], $evaluadas),
            'resumen' => [
                'crear' => collect($evaluadas)->where('accion', 'crear')->count(),
                'con_error' => collect($evaluadas)->where('accion', 'error')->count(),
            ],
        ];
    }

    /** @return array{creados: int, errores: array<int, array{nombre: string, motivo: string}>} */
    public function importar(Empresa $empresa, UploadedFile $archivo): array
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        $evaluadas = $this->evaluarFilas($empresa, $filas);

        $creados = 0;
        $errores = [];

        foreach ($evaluadas as $fila) {
            if ($fila['accion'] === 'error') {
                $errores[] = ['nombre' => $fila['nombre'], 'motivo' => implode(' ', $fila['errores'])];

                continue;
            }

            try {
                DB::transaction(fn () => $this->colaboradores->crear($empresa, $fila['datos']));
                $creados++;
            } catch (Throwable $e) {
                $errores[] = ['nombre' => $fila['nombre'], 'motivo' => $e->getMessage()];
            }
        }

        return ['creados' => $creados, 'errores' => $errores];
    }

    /** @return array<int, array{nombre: string, numero_documento: ?string, accion: string, errores: array<int, string>, datos: ?array}> */
    private function evaluarFilas(Empresa $empresa, array $filas): array
    {
        $sedes = Sede::where('empresa_id', $empresa->id)->where('activa', true)->get()->keyBy(fn ($s) => mb_strtolower($s->nombre));
        $areas = Area::where('empresa_id', $empresa->id)->get()->keyBy(fn ($a) => mb_strtolower($a->nombre));
        $horarios = Horario::where('empresa_id', $empresa->id)->where('activo', true)->get()->keyBy(fn ($h) => mb_strtolower($h->nombre));
        $clavesAfpValidas = ['onp', ...Afp::pluck('clave')->all()];

        $documentosExistentes = Colaborador::withTrashed()
            ->where('empresa_id', $empresa->id)
            ->get(['tipo_documento', 'numero_documento'])
            ->map(fn ($c) => mb_strtolower("{$c->tipo_documento}|{$c->numero_documento}"))
            ->flip();

        $conteoEnArchivo = collect($filas)
            ->countBy(fn ($fila) => mb_strtolower(($fila['tipo_documento'] ?? '').'|'.($fila['numero_documento'] ?? '')));

        $evaluadas = [];
        foreach ($filas as $fila) {
            $evaluadas[] = $this->evaluarFila($empresa, $fila, $sedes, $areas, $horarios, $clavesAfpValidas, $documentosExistentes, $conteoEnArchivo);
        }

        return $evaluadas;
    }

    private function evaluarFila(
        Empresa $empresa,
        array $fila,
        Collection $sedes,
        Collection $areas,
        Collection $horarios,
        array $clavesAfpValidas,
        Collection $documentosExistentes,
        Collection $conteoEnArchivo,
    ): array {
        $nombreCompleto = trim(($fila['nombres'] ?? '').' '.($fila['apellidos'] ?? ''));
        $errores = [];

        $claveDocumento = mb_strtolower(($fila['tipo_documento'] ?? '').'|'.($fila['numero_documento'] ?? ''));
        if ($documentosExistentes->has($claveDocumento)) {
            $errores[] = 'Ya existe un colaborador (activo o eliminado) con este documento en la empresa.';
        }
        if (($conteoEnArchivo[$claveDocumento] ?? 0) > 1) {
            $errores[] = 'Este documento aparece más de una vez en el archivo.';
        }

        $sede = $fila['sede'] ? $sedes->get(mb_strtolower($fila['sede'])) : null;
        if (! $sede) {
            $errores[] = "Sede \"{$fila['sede']}\" no existe o está inactiva.";
        }

        $area = $fila['area'] ? $areas->get(mb_strtolower($fila['area'])) : null;
        if (! $area) {
            $errores[] = "Área \"{$fila['area']}\" no existe en la empresa.";
        }

        $horario = $fila['horario'] ? $horarios->get(mb_strtolower($fila['horario'])) : null;
        if (! $horario) {
            $errores[] = "Horario \"{$fila['horario']}\" no existe o está inactivo.";
        } elseif ($fila['fecha_ingreso'] && ! $this->horarioVigente($horario, $fila['fecha_ingreso'])) {
            $errores[] = 'El horario indicado no está vigente para la fecha de ingreso.';
        }

        if ($fila['sistema_previsional'] && ! in_array($fila['sistema_previsional'], $clavesAfpValidas, true)) {
            $errores[] = "sistema_previsional \"{$fila['sistema_previsional']}\" no es válido (usa \"onp\" o la clave de una AFP registrada).";
        }

        if ($fila['tipo_contrato'] !== 'locacion_servicios' && $fila['periodicidad_pago'] && $fila['periodicidad_pago'] !== 'mensual') {
            $errores[] = 'La periodicidad debe ser mensual para este tipo de contrato.';
        }
        if ($fila['tipo_contrato'] === 'plazo_fijo' && ! $fila['fecha_fin_contrato']) {
            $errores[] = 'fecha_fin_contrato es obligatoria para un contrato a plazo fijo.';
        }

        $datos = null;
        if ($errores === [] && $sede && $area && $horario) {
            $datos = [
                'sede_id' => $sede->id,
                'area_id' => $area->id,
                'horario_id' => $horario->id,
                'nombres' => $fila['nombres'],
                'apellidos' => $fila['apellidos'],
                'tipo_documento' => $fila['tipo_documento'],
                'numero_documento' => $fila['numero_documento'],
                'fecha_nacimiento' => $fila['fecha_nacimiento'],
                'direccion' => $fila['direccion'],
                'email' => $fila['email'],
                'celular_colaborador' => $fila['celular_colaborador'],
                'celular_referencia' => $fila['celular_referencia'],
                'cargo' => $fila['cargo'],
                'tipo_contrato' => $fila['tipo_contrato'],
                'regimen_laboral' => $fila['regimen_laboral'],
                'tipo_trabajador' => $fila['tipo_trabajador'],
                'fecha_ingreso' => $fila['fecha_ingreso'],
                'fecha_fin_contrato' => $fila['fecha_fin_contrato'],
                'sistema_previsional' => $fila['sistema_previsional'],
                'modalidad_trabajo' => $fila['modalidad_trabajo'],
                'salario' => $fila['salario'],
                'moneda_salario' => $fila['moneda_salario'],
                'periodicidad_pago' => $fila['periodicidad_pago'],
                'asignacion_familiar' => $fila['asignacion_familiar'],
            ];

            // El calendario todavía no existe en este punto (se genera abajo
            // con calendarioPorDefecto) — se excluyen sus reglas "required"
            // de esta validación, no hay nada del Excel que validar en ellas.
            $reglas = collect((new StoreColaboradorRequest)->rules())
                ->reject(fn ($regla, $campo) => str_starts_with($campo, 'calendario'))
                ->all();
            $validador = Validator::make($datos, $reglas);
            if ($validador->fails()) {
                $errores = array_merge($errores, $validador->errors()->all());
                $datos = null;
            } else {
                $datos['calendario'] = $this->colaboradores
                    ->calendarioPorDefecto($empresa, $horario, $fila['fecha_ingreso'])['dias'];
            }
        }

        return [
            'nombre' => $nombreCompleto !== '' ? $nombreCompleto : ($fila['numero_documento'] ?? '(sin nombre)'),
            'numero_documento' => $fila['numero_documento'],
            'accion' => $errores === [] ? 'crear' : 'error',
            'errores' => $errores,
            'datos' => $datos,
        ];
    }

    private function horarioVigente(Horario $horario, string $fechaIngreso): bool
    {
        if ($horario->vigencia_desde && $horario->vigencia_desde->toDateString() > $fechaIngreso) {
            return false;
        }

        return ! $horario->vigencia_hasta || $horario->vigencia_hasta->toDateString() >= $fechaIngreso;
    }
}
