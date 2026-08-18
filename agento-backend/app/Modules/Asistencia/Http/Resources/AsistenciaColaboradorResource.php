<?php

namespace App\Modules\Asistencia\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaColaboradorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $asignacion = $this->asignacionesHorario->first();
        $resultados = $this->resultadosAsistencia->keyBy(fn ($resultado) => $resultado->fecha->toDateString());
        $resumen = [
            'dias_trabajados' => $this->resultadosAsistencia->where('estado', 'presente')->count(),
            'faltas' => $this->resultadosAsistencia->where('estado', 'falta')->count(),
            'tardanzas' => $this->resultadosAsistencia->where('minutos_tardanza', '>', 0)->count(),
            'minutos_extra' => $this->resultadosAsistencia->sum('minutos_extra_observados'),
            'descansos_feriados' => $this->resultadosAsistencia->whereIn('tipo_dia', ['descanso', 'feriado'])->count(),
            'home_office' => $this->resultadosAsistencia->where('estado', 'home_office')->count(),
        ];

        return [
            'id' => $this->id,
            'nombre_completo' => trim($this->nombres.' '.$this->apellidos),
            'documento' => $this->numero_documento,
            'legajo' => $this->legajo,
            'person_id' => $this->marcacionesAsistencia->first()?->person_id,
            'empresa' => $this->empresa?->nombre,
            'sede' => $this->sede?->nombre,
            'area' => $this->area?->nombre,
            'cargo' => $this->cargo,
            'horario' => $asignacion?->horario?->nombre ?? $this->horario?->nombre,
            'tiene_horario' => (bool) ($asignacion?->horario ?? $this->horario),
            'tiene_calendario' => $this->calendario->isNotEmpty(),
            'incidencias_pendientes' => $this->incidenciasAsistencia->where('estado', 'pendiente')->count(),
            'resumen' => $resumen,
            'calendario' => $this->calendario->keyBy(fn ($dia) => $dia->fecha->toDateString())
                ->map(fn ($dia) => $dia->tipo),
            'resultados' => $resultados->map(fn ($resultado) => [
                'id' => $resultado->id,
                'estado' => $resultado->estado,
                'tipo_dia' => $resultado->tipo_dia,
                'entrada' => $resultado->entrada_at?->format('Y-m-d H:i:s'),
                'salida' => $resultado->salida_at?->format('Y-m-d H:i:s'),
                'minutos_tardanza' => $resultado->minutos_tardanza,
                'minutos_extra' => $resultado->minutos_extra_observados,
            ]),
            'marcaciones' => $this->marcacionesAsistencia->map(fn ($marcacion) => [
                'id' => $marcacion->id,
                'marcado_at' => $marcacion->marcado_at?->format('Y-m-d H:i:s'),
                'origen' => $marcacion->origen,
                'dispositivo' => $marcacion->dispositivo,
                'estado_procesamiento' => $marcacion->anulada_at ? 'anulada' : ($marcacion->colaborador_id ? 'asociada' : 'pendiente'),
            ]),
            'incidencias' => $this->whenLoaded('incidenciasAsistencia', fn () => $this->incidenciasAsistencia->map(fn ($incidencia) => [
                'id' => $incidencia->id, 'fecha' => $incidencia->fecha?->toDateString(), 'tipo' => $incidencia->tipo,
                'estado' => $incidencia->estado, 'descripcion' => $incidencia->descripcion, 'motivo_resolucion' => $incidencia->motivo_resolucion,
            ])),
            'permisos' => $this->whenLoaded('permisosAsistencia', fn () => $this->permisosAsistencia->map(fn ($permiso) => [
                'id' => $permiso->id, 'tipo' => $permiso->tipo, 'fecha_inicio' => $permiso->fecha_inicio?->toDateString(),
                'fecha_fin' => $permiso->fecha_fin?->toDateString(), 'estado' => $permiso->estado, 'motivo' => $permiso->motivo,
            ])),
            'horas_extra' => $this->whenLoaded('horasExtraAsistencia', fn () => $this->horasExtraAsistencia->map(fn ($extra) => [
                'id' => $extra->id, 'fecha' => $extra->fecha?->toDateString(), 'tasa' => $extra->tasa,
                'minutos_observados' => $extra->minutos_observados, 'minutos_aprobados' => $extra->minutos_aprobados, 'estado' => $extra->estado,
            ])),
            'historial' => $this->whenLoaded('auditoriaAsistencia', fn () => $this->auditoriaAsistencia->map(fn ($evento) => [
                'id' => $evento->id, 'accion' => $evento->accion, 'motivo' => $evento->motivo,
                'fecha' => $evento->created_at?->format('Y-m-d H:i:s'), 'usuario_id' => $evento->usuario_id,
            ])),
        ];
    }
}
