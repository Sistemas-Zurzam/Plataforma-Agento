<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Infrastructure\AlmacenDocumentosCandidato;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Consentimiento;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Models\EntrevistaEvaluacion;
use App\Modules\Reclutamiento\Models\EvaluacionAts;
use App\Modules\Reclutamiento\Models\IntentoRespuesta;
use App\Modules\Reclutamiento\Models\Invitacion;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\PruebaIntento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vencimiento del banco de talentos (regla de negocio configurable, por
 * defecto 24 meses desde la última postulación).
 *
 * Se anonimiza la candidatura: datos personales, CV y documentos (archivo y
 * ruta), antecedentes, habilidades, respuestas de pruebas, desgloses ATS
 * (incluidas referencias a proveedores), evaluaciones de entrevista e
 * invitaciones. Se conservan agregados no personales (etapa, estado, fuente,
 * fechas) para indicadores.
 *
 * NUNCA toca datos laborales de Personas (colaboradores, legajos, contratos):
 * la candidatura y el expediente laboral son registros distintos. Por
 * defecto también se excluyen los candidatos contratados (excepción
 * configurada) y los que tienen postulaciones vigentes.
 */
class RetencionCandidatosService
{
    public function __construct(private readonly AlmacenDocumentosCandidato $documentos) {}

    public function candidatosVencidos(?int $empresaId = null): Builder
    {
        $limite = now()->subMonths((int) config('reclutamiento.retencion.meses', 24));

        return Candidato::withoutGlobalScopes()
            ->whereNull('anonimizado_at')
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->where(fn ($q) => $q->where('ultima_postulacion_at', '<', $limite)
                ->orWhere(fn ($s) => $s->whereNull('ultima_postulacion_at')->where('created_at', '<', $limite)))
            ->whereDoesntHave('postulaciones', fn ($q) => $q->withoutGlobalScopes()->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA]))
            ->when(config('reclutamiento.retencion.excluir_contratados', true), fn ($q) => $q
                ->whereDoesntHave('postulaciones', fn ($p) => $p->withoutGlobalScopes()->where('estado', FlujoPostulacion::CONTRATADA)));
    }

    public function anonimizar(Candidato $candidato): void
    {
        DB::transaction(function () use ($candidato) {
            $postulacionIds = Postulacion::withoutGlobalScopes()->where('candidato_id', $candidato->id)->pluck('id');

            DocumentoCandidato::withoutGlobalScopes()->where('candidato_id', $candidato->id)->get()
                ->each(fn (DocumentoCandidato $doc) => $this->documentos->eliminarArchivo($doc));
            DocumentoCandidato::withoutGlobalScopes()->where('candidato_id', $candidato->id)
                ->update(['nombre_original' => 'eliminado', 'sha256' => '']);

            $intentoIds = PruebaIntento::whereIn('asignacion_id', DB::table('seleccion_prueba_asignaciones')->whereIn('postulacion_id', $postulacionIds)->pluck('id'))->pluck('id');
            IntentoRespuesta::whereIn('intento_id', $intentoIds)->delete();

            EvaluacionAts::whereIn('postulacion_id', $postulacionIds)->update(['desglose' => json_encode(['eliminado_por_retencion' => true]), 'proveedor' => 'eliminado']);
            EntrevistaEvaluacion::whereIn('entrevista_id', DB::table('seleccion_entrevistas')->whereIn('postulacion_id', $postulacionIds)->pluck('id'))
                ->update(['fortalezas' => '', 'riesgos' => '', 'comentarios' => null]);

            Postulacion::withoutGlobalScopes()->whereIn('id', $postulacionIds)->update([
                'perfil_snapshot' => null, 'motivo_interno' => null, 'mensaje_publico' => null,
            ]);
            Invitacion::withoutGlobalScopes()->where('candidato_id', $candidato->id)->delete();
            Consentimiento::where('candidato_id', $candidato->id)->update(['ip' => null]);

            $candidato->antecedentes()->delete();
            $candidato->habilidades()->delete();
            $candidato->etiquetas()->detach();
            $candidato->forceFill([
                'email' => "anonimo-{$candidato->id}@anonimizado.invalid",
                'nombres' => 'Candidato',
                'apellido_paterno' => 'anonimizado',
                'apellido_materno' => null,
                'tipo_documento' => null,
                'numero_documento' => null,
                'celular' => null,
                'ubicacion' => null,
                'resumen' => null,
                'anonimizado_at' => now(),
            ])->save();
        });
    }
}
