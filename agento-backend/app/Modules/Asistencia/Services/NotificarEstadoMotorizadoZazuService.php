<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Jobs\NotificarEstadoMotorizadoZazuJob;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;

/**
 * Punto de entrada único para avisarle a plataforma_zazu que un motorizado
 * cambió de estado — RegistrarMarcacionCarnetService la llama después de
 * cada entrada/salida ya resuelta. Config vía `services.zazu` (.env) en vez
 * de una tabla: es una integración de UNA sola empresa (Zazu), no un
 * catálogo — ver el comentario en config/services.php.
 *
 * `cargo` es texto libre (sin catálogo, ver Colaborador::$fillable), por
 * eso la comparación es insensible a mayúsculas/espacios en vez de un
 * `where('cargo', 'Motorizado')` exacto.
 */
class NotificarEstadoMotorizadoZazuService
{
    public const ESTADO_DISPONIBLE = 'disponible';

    public const ESTADO_DESCANSO = 'descanso';

    public function notificar(Colaborador $colaborador, string $estado): void
    {
        if (! $this->esMotorizadoDeZazu($colaborador)) {
            return;
        }

        if (! config('services.zazu.webhook_url')) {
            return;
        }

        NotificarEstadoMotorizadoZazuJob::dispatch(
            $colaborador->numero_documento,
            $estado,
            trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            Carbon::now()->toIso8601String(),
        );
    }

    private function esMotorizadoDeZazu(Colaborador $colaborador): bool
    {
        $esMotorizado = trim(mb_strtolower($colaborador->cargo ?? '')) === 'motorizado';
        $esDeZazu = (string) $colaborador->empresa_id === (string) config('services.zazu.empresa_id');

        return $esMotorizado && $esDeZazu;
    }
}
