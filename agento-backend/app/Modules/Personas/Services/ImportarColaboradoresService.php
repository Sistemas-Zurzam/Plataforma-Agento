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

    /** @param Collection<int, Empresa> $empresasAutorizadas */
    public function previsualizar(Collection $empresasAutorizadas, UploadedFile $archivo): array
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        $evaluadas = $this->evaluarFilas($empresasAutorizadas, $filas);

        return [
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_tamano' => $archivo->getSize(),
            'filas_invalidas' => $this->lector->filasInvalidas(),
            'colaboradores_detectados' => count($evaluadas),
            'colaboradores' => array_map(fn ($fila) => [
                'nombre' => $fila['nombre'],
                'numero_documento' => $fila['numero_documento'],
                'empresa' => $fila['empresa_texto'],
                'accion' => $fila['accion'],
                'errores' => $fila['errores'],
            ], $evaluadas),
            'resumen' => [
                'crear' => collect($evaluadas)->where('accion', 'crear')->count(),
                'con_error' => collect($evaluadas)->where('accion', 'error')->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Empresa>  $empresasAutorizadas
     * @return array{creados: int, errores: array<int, array{nombre: string, motivo: string}>}
     */
    public function importar(Collection $empresasAutorizadas, UploadedFile $archivo): array
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        $evaluadas = $this->evaluarFilas($empresasAutorizadas, $filas);

        $creados = 0;
        $errores = [];

        foreach ($evaluadas as $fila) {
            if ($fila['accion'] === 'error') {
                $errores[] = ['nombre' => $fila['nombre'], 'motivo' => implode(' ', $fila['errores'])];

                continue;
            }

            try {
                DB::transaction(fn () => $this->colaboradores->crear($fila['empresa'], $fila['datos']));
                $creados++;
            } catch (Throwable $e) {
                $errores[] = ['nombre' => $fila['nombre'], 'motivo' => $e->getMessage()];
            }
        }

        return ['creados' => $creados, 'errores' => $errores];
    }

    /**
     * @param  Collection<int, Empresa>  $empresasAutorizadas
     * @return array<int, array{nombre: string, numero_documento: ?string, empresa_texto: ?string, accion: string, errores: array<int, string>, datos: ?array, empresa: ?Empresa}>
     */
    private function evaluarFilas(Collection $empresasAutorizadas, array $filas): array
    {
        $empresasPorNombre = $empresasAutorizadas->keyBy(fn (Empresa $e) => mb_strtolower($e->nombre_comercial));
        $empresaIds = $empresasAutorizadas->pluck('id')->all();

        // Sede/área se agrupan por empresa porque el archivo puede traer
        // filas de varias empresas del grupo a la vez — cada fila solo debe
        // poder resolver sede/área/documento dentro de SU propia empresa.
        $sedesPorEmpresa = Sede::whereIn('empresa_id', $empresaIds)->where('activa', true)->get()
            ->groupBy('empresa_id')
            ->map(fn ($grupo) => $grupo->keyBy(fn ($s) => mb_strtolower($s->nombre)));
        $areasPorEmpresa = Area::whereIn('empresa_id', $empresaIds)->get()
            ->groupBy('empresa_id')
            ->map(fn ($grupo) => $grupo->keyBy(fn ($a) => mb_strtolower($a->nombre)));
        // Horarios es un catálogo global — se busca entre TODOS los horarios
        // del sistema, no solo los de las empresas autorizadas.
        $horarios = Horario::where('activo', true)->get()->keyBy(fn ($h) => mb_strtolower($h->nombre));
        $clavesAfpValidas = ['onp', ...Afp::pluck('clave')->all()];

        $documentosExistentes = Colaborador::withTrashed()
            ->whereIn('empresa_id', $empresaIds)
            ->get(['empresa_id', 'tipo_documento', 'numero_documento'])
            ->map(fn ($c) => mb_strtolower("{$c->empresa_id}|{$c->tipo_documento}|{$c->numero_documento}"))
            ->flip();

        // El mismo documento SÍ puede repetirse entre empresas distintas (la
        // unicidad real es por empresa), por eso el nombre de empresa tal
        // como vino en el archivo forma parte de la clave de duplicado.
        $conteoEnArchivo = collect($filas)
            ->countBy(fn ($fila) => mb_strtolower(($fila['empresa'] ?? '').'|'.($fila['tipo_documento'] ?? '').'|'.($fila['numero_documento'] ?? '')));

        $evaluadas = [];
        foreach ($filas as $fila) {
            $evaluadas[] = $this->evaluarFila($fila, $empresasPorNombre, $sedesPorEmpresa, $areasPorEmpresa, $horarios, $clavesAfpValidas, $documentosExistentes, $conteoEnArchivo);
        }

        return $evaluadas;
    }

    private function evaluarFila(
        array $fila,
        Collection $empresasPorNombre,
        Collection $sedesPorEmpresa,
        Collection $areasPorEmpresa,
        Collection $horarios,
        array $clavesAfpValidas,
        Collection $documentosExistentes,
        Collection $conteoEnArchivo,
    ): array {
        $nombreCompleto = trim(($fila['nombres'] ?? '').' '.($fila['apellido_paterno'] ?? '').' '.($fila['apellido_materno'] ?? ''));
        $errores = [];

        $empresa = $fila['empresa'] ? $empresasPorNombre->get(mb_strtolower($fila['empresa'])) : null;
        if (! $empresa) {
            $errores[] = $fila['empresa']
                ? "Empresa \"{$fila['empresa']}\" no existe o no tienes acceso a ella."
                : 'empresa es obligatoria — indica a qué empresa pertenece este colaborador.';
        }

        $claveConteo = mb_strtolower(($fila['empresa'] ?? '').'|'.($fila['tipo_documento'] ?? '').'|'.($fila['numero_documento'] ?? ''));
        if (($conteoEnArchivo[$claveConteo] ?? 0) > 1) {
            $errores[] = 'Este documento aparece más de una vez en el archivo para esta empresa.';
        }

        if ($empresa) {
            $claveDocumento = mb_strtolower("{$empresa->id}|{$fila['tipo_documento']}|{$fila['numero_documento']}");
            if ($documentosExistentes->has($claveDocumento)) {
                $errores[] = 'Ya existe un colaborador (activo o eliminado) con este documento en esa empresa.';
            }
        }

        $sedes = $empresa ? ($sedesPorEmpresa->get($empresa->id) ?? collect()) : collect();
        $sede = $fila['sede'] ? $sedes->get(mb_strtolower($fila['sede'])) : null;
        if (! $sede) {
            $errores[] = $empresa
                ? "Sede \"{$fila['sede']}\" no existe o está inactiva en esa empresa."
                : "Sede \"{$fila['sede']}\" no se pudo validar porque la empresa no fue reconocida.";
        }

        $areas = $empresa ? ($areasPorEmpresa->get($empresa->id) ?? collect()) : collect();
        $area = $fila['area'] ? $areas->get(mb_strtolower($fila['area'])) : null;
        if (! $area) {
            $errores[] = $empresa
                ? "Área \"{$fila['area']}\" no existe en esa empresa."
                : "Área \"{$fila['area']}\" no se pudo validar porque la empresa no fue reconocida.";
        }

        // V3 P3 — trabajador de confianza no necesita horario obligatorio;
        // para cualquier otro sigue siendo requerido.
        $esConfianza = (bool) $fila['es_trabajador_confianza'];
        $horario = $fila['horario'] ? $horarios->get(mb_strtolower($fila['horario'])) : null;
        if (! $horario) {
            if (! $esConfianza) {
                $errores[] = $fila['horario']
                    ? "Horario \"{$fila['horario']}\" no existe o está inactivo."
                    : 'horario es obligatorio — indica cuál le corresponde (déjalo vacío solo si es trabajador de confianza).';
            }
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

        // El sistema nunca adivina el día de descanso de un horario rotativo
        // — mismo chequeo que StoreColaboradorRequest::withValidator(), que
        // acá no se ejecuta solo (Validator::make() no dispara los hooks de
        // un FormRequest, solo sus rules()).
        if ($horario?->tipo_turno === 'rotativo' && ! $fila['dias_descanso_rotativo_por_semana']) {
            $errores[] = 'dias_descanso_rotativo_por_semana es obligatorio — el horario elegido es rotativo y el sistema nunca lo adivina.';
        }

        $datos = null;
        if ($errores === [] && $empresa && $sede && $area && ($horario || $esConfianza)) {
            $datos = [
                'sede_id' => $sede->id,
                'area_id' => $area->id,
                'horario_id' => $horario?->id,
                'nombres' => $fila['nombres'],
                'apellido_paterno' => $fila['apellido_paterno'],
                'apellido_materno' => $fila['apellido_materno'],
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
                'es_trabajador_confianza' => $fila['es_trabajador_confianza'],
                'fecha_ingreso' => $fila['fecha_ingreso'],
                'fecha_fin_contrato' => $fila['fecha_fin_contrato'],
                'sistema_previsional' => $fila['sistema_previsional'],
                'modalidad_trabajo' => $fila['modalidad_trabajo'],
                'salario' => $fila['salario'],
                'moneda_salario' => $fila['moneda_salario'],
                'periodicidad_pago' => $fila['periodicidad_pago'],
                'asignacion_familiar' => $fila['asignacion_familiar'],
                'pais_residencia' => $fila['pais_residencia'],
                'ciudad_residencia' => $fila['ciudad_residencia'],
                'distrito_residencia' => $fila['distrito_residencia'],
                'contabilizar_tardanzas' => $fila['contabilizar_tardanzas'],
                'contabilizar_faltas' => $fila['contabilizar_faltas'],
                'contabilizar_horas_extra' => $fila['contabilizar_horas_extra'],
                'cts_cuenta' => $fila['cts_cuenta'],
                'banco' => $fila['banco'],
                'numero_cuenta' => $fila['numero_cuenta'],
                'tipo_cuenta' => $fila['tipo_cuenta'],
                'moneda_cuenta' => $fila['moneda_cuenta'],
                'cci' => $fila['cci'],
                'tolerancia_particular_minutos' => $fila['tolerancia_particular_minutos'],
                'dias_descanso_rotativo_por_semana' => $fila['dias_descanso_rotativo_por_semana'],
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
                // Sin horario (confianza) no hay calendario que generar.
                $datos['calendario'] = $horario
                    ? $this->colaboradores->calendarioPorDefecto($horario, $fila['fecha_ingreso'])['dias']
                    : [];
            }
        }

        return [
            'nombre' => $nombreCompleto !== '' ? $nombreCompleto : ($fila['numero_documento'] ?? '(sin nombre)'),
            'numero_documento' => $fila['numero_documento'],
            'empresa_texto' => $fila['empresa'],
            'accion' => $errores === [] ? 'crear' : 'error',
            'errores' => $errores,
            'datos' => $datos,
            'empresa' => $empresa,
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
