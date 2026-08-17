<?php

namespace App\Modules\Asistencia\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaSolicitudArea;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AsistenciaDecisionService
{
    public function __construct(
        private readonly AsistenciaAuditoriaService $auditoria,
        private readonly \App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
    ) {}

    public function resolverIncidencia(Empresa $empresa, AsistenciaIncidencia $incidencia, array $datos, User $usuario): AsistenciaIncidencia
    {
        $this->asegurarEmpresa($empresa, $incidencia);
        return DB::transaction(function () use ($empresa, $incidencia, $datos, $usuario) {
            $antes = $incidencia->toArray();
            $incidencia->update([
                'estado' => $datos['accion'] === 'aprobar' ? 'resuelta' : $datos['accion'],
                'motivo_resolucion' => $datos['motivo'], 'resuelto_por' => $usuario->id, 'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'incidencia_'.$datos['accion'], $incidencia, $datos['motivo'], $antes, $incidencia->fresh()->toArray());
            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    public function resolverHoraExtra(Empresa $empresa, AsistenciaHoraExtra $horaExtra, array $datos, User $usuario): AsistenciaHoraExtra
    {
        $this->asegurarEmpresa($empresa, $horaExtra);
        return DB::transaction(function () use ($empresa, $horaExtra, $datos, $usuario) {
            $antes = $horaExtra->toArray();
            $aprobados = $datos['accion'] === 'aprobar'
                ? min($horaExtra->minutos_observados, $datos['minutos_aprobados'] ?? $horaExtra->minutos_observados) : 0;
            $horaExtra->update([
                'estado' => $datos['accion'] === 'aprobar' ? 'aprobado' : $datos['accion'],
                'minutos_aprobados' => $aprobados, 'motivo' => $datos['motivo'],
                'resuelto_por' => $usuario->id, 'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'hora_extra_'.$datos['accion'], $horaExtra, $datos['motivo'], $antes, $horaExtra->fresh()->toArray());
            return $horaExtra->fresh('colaborador');
        });
    }

    public function resolverSolicitud(Empresa $empresa, AsistenciaSolicitudArea $solicitud, array $datos, User $usuario, bool $esRrhh): AsistenciaSolicitudArea
    {
        $this->asegurarEmpresa($empresa, $solicitud);
        if (! $esRrhh && $usuario->area_id !== $solicitud->area_id) {
            abort(403, 'La solicitud no pertenece al área bajo tu responsabilidad.');
        }
        return DB::transaction(function () use ($empresa, $solicitud, $datos, $usuario, $esRrhh) {
            $antes = $solicitud->toArray();
            if ($datos['accion'] === 'aprobar') {
                $estado = $esRrhh ? 'finalizada' : 'pendiente_rrhh';
            } else {
                $estado = $datos['accion'] === 'observar' ? ($esRrhh ? 'observada_rrhh' : 'observada_responsable') : 'rechazada';
            }
            $solicitud->update($esRrhh ? [
                'estado' => $estado, 'rrhh_por' => $usuario->id, 'rrhh_at' => now(), 'observacion_rrhh' => $datos['motivo'],
            ] : [
                'estado' => $estado, 'responsable_por' => $usuario->id, 'responsable_at' => now(), 'observacion_responsable' => $datos['motivo'],
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'solicitud_'.$datos['accion'], $solicitud, $datos['motivo'], $antes, $solicitud->fresh()->toArray());
            return $solicitud->fresh(['area', 'colaboradores.area']);
        });
    }

    public function resolverPermiso(Empresa $empresa, AsistenciaPermiso $permiso, array $datos, User $usuario): AsistenciaPermiso
    {
        $this->asegurarEmpresa($empresa, $permiso);
        $this->periodos->asegurarRangoEditable(
            $empresa->id, $permiso->fecha_inicio->toDateString(), $permiso->fecha_fin->toDateString()
        );
        $resuelto = DB::transaction(function () use ($empresa, $permiso, $datos, $usuario) {
            $antes = $permiso->toArray();
            $permiso->update([
                'estado' => $datos['accion'] === 'aprobar' ? 'aprobado' : 'rechazado',
                'resuelto_por' => $usuario->id, 'resuelto_at' => now(), 'observacion_resolucion' => $datos['motivo'],
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'permiso_'.$datos['accion'], $permiso, $datos['motivo'], $antes, $permiso->fresh()->toArray());
            return $permiso->fresh('colaborador.area');
        });
        if ($resuelto->estado === 'aprobado') {
            for ($fecha = Carbon::parse($resuelto->fecha_inicio); $fecha->lte($resuelto->fecha_fin); $fecha->addDay()) {
                $this->procesador->procesar($resuelto->colaborador, $fecha);
            }
        }
        return $resuelto;
    }

    public function editarDia(Empresa $empresa, AsistenciaResultadoDiario $resultado, array $datos, User $usuario): AsistenciaResultadoDiario
    {
        $this->asegurarEmpresa($empresa, $resultado);
        $fecha = $resultado->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);
        $antes = $resultado->load('marcaciones')->toArray();
        AsistenciaMarcacion::query()->where('empresa_id', $empresa->id)
            ->where('colaborador_id', $resultado->colaborador_id)->where('origen', 'manual_rrhh')
            ->whereDate('marcado_at', $fecha)->whereNull('anulada_at')
            ->update(['anulada_at' => now(), 'anulada_por' => $usuario->id]);
        foreach (['entrada', 'salida'] as $campo) {
            if (! empty($datos[$campo])) {
                AsistenciaMarcacion::query()->firstOrCreate([
                    'empresa_id' => $empresa->id,
                    'person_id' => $resultado->colaborador->numero_documento,
                    'marcado_at' => Carbon::parse($fecha.' '.$datos[$campo]),
                    'origen' => 'manual_rrhh',
                ], ['colaborador_id' => $resultado->colaborador_id, 'dispositivo' => 'Edición RR.HH.']);
            }
        }
        $actualizado = $this->procesador->procesar($resultado->colaborador, $resultado->fecha);
        if (! empty($datos['estado'])) $actualizado->update(['estado' => $datos['estado']]);
        $this->auditoria->registrar($empresa->id, $usuario->id, 'resultado_editado', $actualizado, $datos['motivo'], $antes, $actualizado->fresh('marcaciones')->toArray());
        return $actualizado->fresh(['colaborador.area', 'incidencias', 'marcaciones']);
    }

    private function asegurarEmpresa(Empresa $empresa, Model $modelo): void
    {
        abort_unless((int) $modelo->getAttribute('empresa_id') === (int) $empresa->id, 404);
    }
}
