<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Models\Etiqueta;
use Illuminate\Support\Facades\DB;

/**
 * Etiquetas automáticas del banco de talentos, aplicadas por eventos del
 * flujo (siempre dentro de la empresa del candidato):
 * - apto_ats: alcanzó el umbral ATS en alguna postulación.
 * - finalista: llegó a la etapa de selección.
 * - contratado: fue contratado por un proceso.
 */
class EtiquetadoAutomatico
{
    public const NOMBRES = ['apto_ats', 'finalista', 'contratado'];

    public function aplicar(int $empresaId, int $candidatoId, string $nombre): void
    {
        $etiqueta = Etiqueta::withoutGlobalScopes()->firstOrCreate(
            ['empresa_id' => $empresaId, 'nombre' => $nombre],
            ['tipo' => Etiqueta::AUTOMATICA],
        );

        DB::table('seleccion_candidato_etiqueta')->insertOrIgnore([
            'candidato_id' => $candidatoId,
            'etiqueta_id' => $etiqueta->id,
            'origen' => Etiqueta::AUTOMATICA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
