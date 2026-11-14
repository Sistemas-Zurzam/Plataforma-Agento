<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío automático de las pruebas configuradas en el proceso:
 *
 * - Psicométrica: al postular.
 * - Conocimientos: al postular o al pasar el filtro de CV (cuando la
 *   postulación avanza más allá de "filtro_cv"), según el proceso.
 *
 * Se envía la última versión publicada de cada prueba y nunca dos veces la
 * misma familia a una postulación. Un fallo no revierte la postulación ni el
 * movimiento de etapa: RR. HH. puede asignarla a mano desde la ficha.
 */
class EnvioAutomaticoPruebas
{
    public const AL_POSTULAR = 'postular';

    public const AL_PASAR_FILTRO_CV = 'filtro_cv';

    public const MOMENTOS = [self::AL_POSTULAR, self::AL_PASAR_FILTRO_CV];

    private const ETAPAS_ANTES_DEL_FILTRO = ['postulacion', 'filtro_cv'];

    public function __construct(private readonly AplicacionPruebaService $aplicacion) {}

    public function alPostular(Postulacion $postulacion): void
    {
        $proceso = $postulacion->proceso()->withoutGlobalScopes()->first();
        if (! $proceso) {
            return;
        }

        $this->enviar($postulacion, $proceso->pruebaVigente());
        if ($proceso->conocimientos_momento === self::AL_POSTULAR) {
            $this->enviar($postulacion, $proceso->pruebaVigente($proceso->conocimientos_familia));
        }
    }

    /**
     * Llamado después de mover la postulación de etapa.
     */
    public function alMoverDeEtapa(Postulacion $postulacion, string $etapaAnterior): void
    {
        $pasoElFiltro = in_array($etapaAnterior, self::ETAPAS_ANTES_DEL_FILTRO, true)
            && ! in_array($postulacion->etapa, self::ETAPAS_ANTES_DEL_FILTRO, true);
        if (! $pasoElFiltro || ! FlujoPostulacion::esVigente($postulacion->estado)) {
            return;
        }

        $proceso = $postulacion->proceso()->withoutGlobalScopes()->first();
        if ($proceso?->conocimientos_momento === self::AL_PASAR_FILTRO_CV) {
            $this->enviar($postulacion, $proceso->pruebaVigente($proceso->conocimientos_familia));
        }
    }

    private function enviar(Postulacion $postulacion, ?Prueba $prueba): void
    {
        if (! $prueba) {
            return;
        }

        $yaTiene = PruebaAsignacion::withoutGlobalScopes()
            ->where('postulacion_id', $postulacion->id)
            ->whereHas('prueba', fn ($q) => $q->withoutGlobalScopes()->where('familia', $prueba->familia))
            ->exists();
        if ($yaTiene) {
            return;
        }

        try {
            $this->aplicacion->asignarAlPostular($postulacion->loadMissing('candidato'), $prueba);
        } catch (Throwable $e) {
            Log::warning('Reclutamiento: no se pudo enviar automáticamente una prueba.', [
                'postulacion_id' => $postulacion->id, 'prueba_id' => $prueba->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
