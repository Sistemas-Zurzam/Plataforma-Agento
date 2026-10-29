<?php

namespace App\Modules\Reclutamiento\Jobs;

use App\Modules\Reclutamiento\Application\EvaluacionAtsService;
use App\Modules\Reclutamiento\Domain\Contracts\ExtractorCv;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracción de CV (si hay proveedor) y cálculo ATS inicial de una
 * postulación. Recibe empresa_id resuelto por el servidor y la postulación se
 * busca acotada a esa empresa: el job nunca opera fuera de ese contexto.
 * Un error o timeout aquí no afecta la postulación ya registrada.
 */
class EvaluarPostulacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly int $empresaId,
        private readonly int $postulacionId,
    ) {}

    public function handle(EvaluacionAtsService $ats, ExtractorCv $extractor): void
    {
        $postulacion = Postulacion::withoutGlobalScopes()
            ->where('empresa_id', $this->empresaId)
            ->whereKey($this->postulacionId)
            ->first();

        if (! $postulacion) {
            return;
        }

        $postulacion->update(['extraccion_estado' => $extractor->disponible() ? 'pendiente' : 'no_disponible']);

        if ($extractor->disponible()) {
            // La extracción solo aporta datos sugeridos; la evaluación usa lo
            // declarado por el candidato y lo validado por RR. HH.
            try {
                $cv = $postulacion->cv()->withoutGlobalScopes()->first();
                if ($cv?->ruta) {
                    $extractor->extraer(Storage::disk($cv->disco)->path($cv->ruta), $cv->mime);
                }
                $postulacion->update(['extraccion_estado' => 'completada']);
            } catch (Throwable $e) {
                Log::warning('Reclutamiento: extracción de CV fallida.', ['postulacion_id' => $postulacion->id, 'error' => class_basename($e)]);
                $postulacion->update(['extraccion_estado' => 'error']);
            }
        }

        try {
            $ats->calcularInicial($postulacion);
        } catch (Throwable $e) {
            Log::warning('Reclutamiento: cálculo ATS inicial fallido.', ['postulacion_id' => $postulacion->id, 'error' => class_basename($e)]);
        }
    }
}
