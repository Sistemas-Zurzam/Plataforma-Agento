<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Http\Requests\StoreHorarioRequest;
use App\Modules\Asistencia\Infrastructure\HorarioXlsxReader;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Importador de horarios vía Excel — misma forma que ImportarMarcacionesService
 * (previsualizar() de solo lectura + importar() que persiste), y reutiliza
 * HorarioService::crear()/actualizar() para no duplicar las reglas de negocio
 * de un horario (Sección 98 del proyecto: "no duplicar motores").
 */
class ImportarHorariosService
{
    public function __construct(
        private readonly HorarioXlsxReader $lector,
        private readonly HorarioService $horarios,
    ) {}

    public function previsualizar(Empresa $empresa, UploadedFile $archivo): array
    {
        $grupos = $this->agruparPorHorario($this->lector->leer($archivo->getRealPath()));
        $existentes = Horario::where('empresa_id', $empresa->id)
            ->withCount('asignaciones')
            ->get()
            ->keyBy(fn (Horario $horario) => mb_strtolower($horario->nombre));

        $filas = [];
        foreach ($grupos as $nombre => $dias) {
            $filas[] = $this->evaluarGrupo($nombre, $dias, $existentes->get(mb_strtolower($nombre)));
        }

        return [
            'archivo_nombre' => $archivo->getClientOriginalName(),
            'archivo_tamano' => $archivo->getSize(),
            'filas_invalidas' => $this->lector->filasInvalidas(),
            'horarios_detectados' => count($filas),
            'horarios' => $filas,
            'resumen' => [
                'crear' => collect($filas)->where('accion', 'crear')->count(),
                'actualizar' => collect($filas)->where('accion', 'actualizar')->count(),
                'bloqueados' => collect($filas)->where('accion', 'bloqueado')->count(),
                'con_error' => collect($filas)->where('accion', 'error')->count(),
            ],
        ];
    }

    /** @return array{creados: int, actualizados: int, omitidos: int, errores: array<int, array{nombre: string, motivo: string}>} */
    public function importar(Empresa $empresa, UploadedFile $archivo): array
    {
        $grupos = $this->agruparPorHorario($this->lector->leer($archivo->getRealPath()));
        $existentes = Horario::where('empresa_id', $empresa->id)
            ->withCount('asignaciones')
            ->get()
            ->keyBy(fn (Horario $horario) => mb_strtolower($horario->nombre));

        $creados = $actualizados = $omitidos = 0;
        $errores = [];

        foreach ($grupos as $nombre => $dias) {
            $evaluado = $this->evaluarGrupo($nombre, $dias, $existentes->get(mb_strtolower($nombre)));

            if ($evaluado['accion'] === 'error') {
                $errores[] = ['nombre' => $nombre, 'motivo' => implode(' ', $evaluado['errores'])];

                continue;
            }
            if ($evaluado['accion'] === 'bloqueado') {
                $omitidos++;

                continue;
            }

            try {
                DB::transaction(function () use ($empresa, $evaluado, $existentes, $nombre) {
                    if ($evaluado['accion'] === 'actualizar') {
                        $this->horarios->actualizar($empresa, $existentes->get(mb_strtolower($nombre)), $evaluado['datos']);
                    } else {
                        $this->horarios->crear($empresa, $evaluado['datos']);
                    }
                });
                $evaluado['accion'] === 'actualizar' ? $actualizados++ : $creados++;
            } catch (Throwable $e) {
                $errores[] = ['nombre' => $nombre, 'motivo' => $e->getMessage()];
            }
        }

        return compact('creados', 'actualizados', 'omitidos', 'errores');
    }

    /** @return array<string, array<int, array<string, mixed>>> nombre_horario => filas de días */
    private function agruparPorHorario(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $grupos[$fila['nombre_horario']][] = $fila;
        }

        return $grupos;
    }

    /**
     * Arma el payload exacto que espera HorarioService::crear()/actualizar()
     * (el mismo shape que StoreHorarioRequest) y lo corre por el mismo
     * validador que usa la creación manual — un solo motor de reglas.
     */
    private function evaluarGrupo(string $nombre, array $diasCrudos, ?Horario $existente): array
    {
        $primero = $diasCrudos[0];
        $datos = [
            'nombre' => $nombre,
            'tolerancia_minutos' => $primero['tolerancia_minutos'] !== null ? (int) $primero['tolerancia_minutos'] : 0,
            'tipo_turno' => $primero['tipo_turno'] ?? 'normal',
            'descripcion' => $primero['descripcion'],
            'cruza_medianoche' => $primero['cruza_medianoche'],
            'vigencia_desde' => $primero['vigencia_desde'],
            'vigencia_hasta' => $primero['vigencia_hasta'],
            'dias' => collect($diasCrudos)->map(fn ($dia) => [
                'dia_semana' => $dia['dia_semana'],
                'estado' => $dia['estado'],
                'hora_entrada' => $dia['hora_entrada'],
                'hora_salida' => $dia['hora_salida'],
                'refrigerio_inicio' => $dia['refrigerio_inicio'],
                'refrigerio_fin' => $dia['refrigerio_fin'],
                'jornada_nocturna' => $dia['jornada_nocturna'],
                'permitir_horas_extra' => $dia['permitir_horas_extra'],
            ])->values()->all(),
        ];

        $validador = Validator::make($datos, (new StoreHorarioRequest)->rules());
        $errores = $validador->fails() ? $validador->errors()->all() : [];
        $errores = array_merge($errores, $this->erroresDeNegocio($datos['dias']));

        if ($errores !== []) {
            return ['nombre' => $nombre, 'accion' => 'error', 'errores' => $errores, 'datos' => null];
        }

        if ($existente && $existente->asignaciones_count > 0) {
            return [
                'nombre' => $nombre,
                'accion' => 'bloqueado',
                'errores' => ["\"{$nombre}\" ya tiene colaboradores asignados — duplícalo manualmente en vez de reimportarlo."],
                'datos' => null,
            ];
        }

        return [
            'nombre' => $nombre,
            'accion' => $existente ? 'actualizar' : 'crear',
            'errores' => [],
            'datos' => $datos,
        ];
    }

    /**
     * Réplica minimalista de las validaciones condicionales que
     * StoreHorarioRequest hace en withValidator() (no reutilizables tal
     * cual porque dependen de una request HTTP real ligada al formulario).
     *
     * @return array<int, string>
     */
    private function erroresDeNegocio(array $dias): array
    {
        $errores = [];
        $diasSemana = collect($dias)->pluck('dia_semana');
        if ($diasSemana->unique()->sort()->values()->all() !== [0, 1, 2, 3, 4, 5, 6]) {
            $errores[] = 'Faltan días de la semana — deben venir exactamente los 7 (Lunes a Domingo) sin repetir.';
        }

        foreach ($dias as $dia) {
            if (($dia['estado'] ?? null) === 'laborable') {
                if (empty($dia['hora_entrada'])) {
                    $errores[] = "Día {$dia['dia_semana']}: falta hora_entrada en un día laborable.";
                }
                if (empty($dia['hora_salida'])) {
                    $errores[] = "Día {$dia['dia_semana']}: falta hora_salida en un día laborable.";
                }
            }
            if ((! empty($dia['refrigerio_inicio'])) !== (! empty($dia['refrigerio_fin']))) {
                $errores[] = "Día {$dia['dia_semana']}: refrigerio_inicio y refrigerio_fin deben venir juntos.";
            }
        }

        return $errores;
    }
}
