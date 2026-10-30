<?php

namespace App\Modules\PortalCliente\Application;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Asistencia\Services\AsistenciaPermisoService;
use App\Modules\Nominas\Application\CiclosCerrados;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicitud de permiso desde el Portal de Empresa. Reutiliza el caso de uso
 * de Asistencia (AsistenciaPermisoService::crear: estado 'pendiente',
 * tipo_ausencia, con_goce derivado, auditoría) y agrega solo las reglas
 * propias del portal: alcance del equipo, origen 'portal', períodos
 * cerrados y solapamientos. RR. HH. lo resuelve desde su bandeja existente
 * (PATCH /asistencia/permisos/{id}). Crear la solicitud nunca justifica
 * incidencias ni reprocesa días.
 */
class SolicitarPermisoPortal
{
    public function __construct(
        private readonly AsistenciaPermisoService $permisos,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly CiclosCerrados $ciclos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(AlcancePortal $alcance, array $datos): AsistenciaPermiso
    {
        $colaborador = $alcance->asegurarColaborador((int) $datos['colaborador_id']);
        $empresaId = $alcance->empresa->id;

        $this->periodos->asegurarRangoEditable($empresaId, $datos['fecha_inicio'], $datos['fecha_fin']);
        $this->ciclos->asegurarAbierto($empresaId, $datos['fecha_inicio'], $datos['fecha_fin'], 'fecha_inicio');

        return DB::transaction(function () use ($alcance, $colaborador, $datos) {
            // Bloquea la ficha mientras se verifica el solapamiento: un doble
            // envío (o dos gerentes a la vez) no puede crear dos permisos.
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            $solapado = AsistenciaPermiso::query()
                ->where('empresa_id', $alcance->empresa->id)
                ->where('colaborador_id', $colaborador->id)
                ->whereIn('estado', ['pendiente', 'aprobado'])
                ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
                ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])
                ->first();
            if ($solapado) {
                throw ValidationException::withMessages(['fecha_inicio' => [sprintf(
                    'El colaborador ya tiene un permiso %s del %s al %s que se cruza con estas fechas.',
                    $solapado->estado, $solapado->fecha_inicio->format('d/m/Y'), $solapado->fecha_fin->format('d/m/Y'),
                )]]);
            }

            return $this->permisos->crear($alcance->empresa, [
                'colaborador_id' => $colaborador->id,
                'tipo' => $datos['tipo'],
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'motivo' => $datos['motivo'],
                'con_goce' => $datos['con_goce'] ?? null,
                'origen' => 'portal',
            ], $alcance->usuario->id);
        });
    }
}
