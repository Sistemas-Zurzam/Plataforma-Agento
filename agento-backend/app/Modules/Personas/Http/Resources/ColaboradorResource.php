<?php

namespace App\Modules\Personas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Modules\Personas\Models\Colaborador
 */
class ColaboradorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $remuneracionVigente = $this->remuneracionVigente;

        return [
            'id' => $this->id,
            'legajo' => $this->legajo,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'nombre_completo' => "{$this->nombres} {$this->apellidos}",
            'tipo_documento' => $this->tipo_documento,
            'numero_documento' => $this->numero_documento,
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'pais_residencia' => $this->pais_residencia,
            'ciudad_residencia' => $this->ciudad_residencia,
            'distrito_residencia' => $this->distrito_residencia,
            'direccion' => $this->direccion,
            'email' => $this->email,
            'celular_colaborador' => $this->celular_colaborador,
            'celular_referencia' => $this->celular_referencia,
            // color/logo_url: usados por el carnet de colaborador para pintar
            // el encabezado con la identidad de la empresa dueña, no de la
            // plataforma.
            'empresa' => $this->whenLoaded('empresa', fn () => [
                'id' => $this->empresa->id,
                'nombre' => $this->empresa->nombre,
                'color' => $this->empresa->color,
                'logo_url' => $this->empresa->logo_path ? Storage::disk('public')->url($this->empresa->logo_path) : null,
            ]),
            'sede' => $this->whenLoaded('sede', fn () => ['id' => $this->sede->id, 'nombre' => $this->sede->nombre]),
            'area' => $this->whenLoaded('area', fn () => ['id' => $this->area->id, 'nombre' => $this->area->nombre]),
            'horario' => $this->whenLoaded('horario', fn () => [
                'id' => $this->horario->id,
                'nombre' => $this->horario->nombre,
                'tipo_turno' => $this->horario->tipo_turno,
            ]),
            'dias_descanso_rotativo_por_semana' => $this->whenLoaded(
                'asignacionesHorario',
                fn () => $this->asignacionesHorario->firstWhere('vigencia_hasta', null)?->dias_descanso_rotativo_por_semana,
            ),
            'cargo' => $this->cargo,
            'tipo_contrato' => $this->tipo_contrato,
            'regimen_laboral' => $this->regimen_laboral,
            'tipo_trabajador' => $this->tipo_trabajador,
            'es_trabajador_confianza' => $this->es_trabajador_confianza,
            'fecha_ingreso' => $this->fecha_ingreso?->toDateString(),
            'fecha_fin_contrato' => $this->fecha_fin_contrato?->toDateString(),
            'fecha_cese' => $this->fecha_cese?->toDateString(),
            'motivo_cese' => $this->motivo_cese,
            'modalidad_trabajo' => $this->modalidad_trabajo,
            'tolerancia_particular_minutos' => $this->tolerancia_particular_minutos,
            'cts_cuenta' => $this->cts_cuenta,
            'sistema_previsional' => $this->sistema_previsional,
            'afp_id' => $this->afp_id,
            'tipo_comision' => $this->tipo_comision,
            'cuspp' => $this->cuspp,
            'tiene_hijos_asignacion_familiar' => $this->tiene_hijos_asignacion_familiar,
            'tiene_suspension_renta_4ta' => $this->tiene_suspension_renta_4ta,
            'banco' => $this->banco,
            'numero_cuenta' => $this->numero_cuenta,
            'tipo_cuenta' => $this->tipo_cuenta,
            'moneda_cuenta' => $this->moneda_cuenta,
            'cci' => $this->cci,
            'activo' => $this->activo,
            'eliminado' => $this->trashed(),
            'eliminado_at' => $this->deleted_at?->toDateString(),
            'remuneracion' => $remuneracionVigente ? [
                'salario' => $remuneracionVigente->salario,
                'moneda_salario' => $remuneracionVigente->moneda_salario,
                'periodicidad_pago' => $remuneracionVigente->periodicidad_pago,
                'asignacion_familiar' => $remuneracionVigente->asignacion_familiar,
            ] : null,
            'historial_remunerativo' => $this->whenLoaded('remuneraciones', fn () => $this->remuneraciones->map(fn ($remuneracion) => [
                'id' => $remuneracion->id,
                'salario' => $remuneracion->salario,
                'moneda_salario' => $remuneracion->moneda_salario,
                'periodicidad_pago' => $remuneracion->periodicidad_pago,
                'asignacion_familiar' => $remuneracion->asignacion_familiar,
                'vigencia_desde' => $remuneracion->vigencia_desde?->toDateString(),
            ])->values()),
            'historial_horario' => $this->whenLoaded('asignacionesHorario', fn () => $this->asignacionesHorario
                ->sortByDesc('vigencia_desde')
                ->map(fn ($asignacion) => [
                    'id' => $asignacion->id,
                    'horario' => $asignacion->horario?->nombre,
                    'vigencia_desde' => $asignacion->vigencia_desde?->toDateString(),
                    'vigencia_hasta' => $asignacion->vigencia_hasta?->toDateString(),
                ])->values()),
            'calendario' => $this->whenLoaded('calendario', fn () => $this->calendario->map(fn ($dia) => [
                'fecha' => $dia->fecha?->toDateString(),
                'tipo' => $dia->tipo,
            ])->values()),
            'documentos' => $this->whenLoaded('documentos', fn () => $this->documentos->map(fn ($documento) => [
                'id' => $documento->id,
                'tipo' => $documento->tipo,
                'nombre_original' => $documento->nombre_original,
                'mime_type' => $documento->mime_type,
                'tamano_bytes' => $documento->tamano_bytes,
                'created_at' => $documento->created_at?->toISOString(),
            ])->values()),
        ];
    }
}
