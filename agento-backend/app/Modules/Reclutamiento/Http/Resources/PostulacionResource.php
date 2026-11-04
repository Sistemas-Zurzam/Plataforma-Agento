<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Postulación para usuarios internos. Puntajes, desglose ATS, motivo interno
 * y snapshot (datos del CV) solo se incluyen para quien puede ver datos
 * sensibles (seleccion.candidatos.gestionar / evaluaciones.gestionar).
 */
class PostulacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AlcanceSeleccion|null $alcance */
        $alcance = $request->attributes->get('alcance_seleccion');
        $sensible = $alcance?->verDatosSensibles() ?? false;
        $candidato = $this->relationLoaded('candidato') ? $this->candidato : null;
        $ats = $this->relationLoaded('evaluacionAtsVigente') ? $this->evaluacionAtsVigente : null;
        $final = $this->relationLoaded('puntajeFinalVigente') ? $this->puntajeFinalVigente : null;

        return [
            'id' => $this->id,
            'codigo_seguimiento' => $this->codigo_seguimiento,
            'proceso' => $this->whenLoaded('proceso', fn () => [
                'id' => $this->proceso->id, 'codigo' => $this->proceso->codigo, 'cargo' => $this->proceso->cargo, 'estado' => $this->proceso->estado,
            ]),
            'candidato' => $candidato ? [
                'id' => $candidato->id,
                'nombre' => $candidato->nombreCompleto(),
                'email' => $sensible ? $candidato->email : null,
                'celular' => $sensible ? $candidato->celular : null,
            ] : null,
            'fuente' => $this->fuente,
            'etapa' => $this->etapa,
            'estado' => $this->estado,
            'etapa_desde_at' => $this->etapa_desde_at?->toIso8601String(),
            'dias_en_etapa' => $this->etapa_desde_at ? (int) floor($this->etapa_desde_at->diffInDays(now())) : null,
            'siguiente_accion' => $this->siguienteAccion($ats),
            'ats' => $sensible && $ats ? [
                'puntaje' => $ats->puntaje,
                'resultado' => $ats->resultado,
                'origen' => $ats->origen,
                'proveedor' => $ats->proveedor,
                'formula' => $ats->formula,
                'perfil_version' => $ats->perfil_version,
            ] : null,
            'puntaje_final' => $sensible && $final ? [
                'puntaje' => $final->puntaje,
                'provisional' => $final->provisional,
                'perfil_version' => $final->perfil_version,
                'componentes' => $final->componentes,
                'pesos' => $final->pesos,
            ] : null,
            'no_aplica' => [
                'ats' => $this->no_aplica_ats,
                'psicometria' => $this->no_aplica_psicometria,
                'entrevistas' => $this->no_aplica_entrevistas,
            ],
            'extraccion_estado' => $this->extraccion_estado,
            'causa_descarte' => $sensible ? $this->causa_descarte : null,
            'motivo_interno' => $sensible ? $this->motivo_interno : null,
            'mensaje_publico' => $this->mensaje_publico,
            'tiene_cv' => $this->cv_documento_id !== null,
            'colaborador_id' => $this->colaborador_id,
            'contratada_at' => $this->contratada_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function siguienteAccion($ats): string
    {
        if ($this->estado === 'seleccionada') {
            return 'Contratar';
        }
        if ($this->estado !== 'activa') {
            return '—';
        }
        if (($this->entrevistas_pendientes ?? 0) > 0) {
            return 'Evaluar entrevista';
        }
        if (($this->pruebas_pendientes ?? 0) > 0) {
            return 'Esperar evaluación del candidato';
        }

        return match ($this->etapa) {
            'postulacion', 'filtro_cv' => $ats?->resultado === 'requiere_revision' || ! $ats ? 'Revisar CV' : 'Decidir filtro de CV',
            'psicometrica' => 'Asignar evaluación',
            'entrevista_virtual', 'entrevista_presencial' => 'Programar entrevista',
            'seleccion' => 'Decidir selección',
            default => '—',
        };
    }
}
