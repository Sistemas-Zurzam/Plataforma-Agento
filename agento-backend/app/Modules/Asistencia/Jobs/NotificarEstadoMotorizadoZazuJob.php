<?php

namespace App\Modules\Asistencia\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Entrega el webhook de estado de motorizado a plataforma_zazu. Nunca debe
 * bloquear ni afectar el registro de asistencia que lo dispara — por eso
 * vive en cola, no en el request del kiosco (ver
 * RegistrarMarcacionCarnetService::notificarEstadoMotorizado()).
 *
 * Autenticación: clave compartida en la cabecera `X-Api-Key` (mismo
 * esquema que ya usa plataforma_zazu para sus otros webhooks entrantes —
 * ver CobranzaValidadaController/RetornoWebhookController allá —, en vez
 * de introducir un esquema de firma nuevo solo para esta integración).
 */
class NotificarEstadoMotorizadoZazuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 600, 1800];

    public function __construct(
        private readonly string $dni,
        private readonly string $estado,
        private readonly string $colaboradorNombre,
        private readonly string $marcadoAtIso,
    ) {}

    public function handle(): void
    {
        $url = config('services.zazu.webhook_url');
        $secreto = config('services.zazu.webhook_secret');

        // La config pudo quitarse entre que se encoló el job y que corrió
        // — no es un error, simplemente ya no hay a quién avisar.
        if (! $url || ! $secreto) {
            return;
        }

        $respuesta = Http::withHeaders(['X-Api-Key' => $secreto])->post($url, [
            'dni' => $this->dni,
            'estado' => $this->estado,
            'colaborador_nombre' => $this->colaboradorNombre,
            'empresa' => 'zazu',
            'marcado_at' => $this->marcadoAtIso,
        ]);

        if ($respuesta->failed()) {
            throw new RuntimeException("plataforma_zazu respondió {$respuesta->status()} al webhook de estado de motorizado.");
        }
    }

    public function failed(?\Throwable $excepcion): void
    {
        Log::error('Se agotaron los reintentos del webhook de estado de motorizado a Zazu.', [
            'dni' => $this->dni,
            'estado' => $this->estado,
            'excepcion' => $excepcion?->getMessage(),
        ]);
    }
}
