<?php

namespace App\Modules\Nominas\Jobs;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\EnvioBoletas\BoletaMail;
use App\Modules\Nominas\Infrastructure\EnvioBoletas\BoletaPdf;
use App\Modules\Nominas\Models\BoletaEnvio;
use App\Modules\Nominas\Services\BoletaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera el PDF de UNA boleta pagada y la envía. El resultado queda en su fila
 * de boleta_envios (enviado/fallido); un fallo no se reintenta solo: RR.HH.
 * ve "Fallido" en la planilla y vuelve a enviar.
 */
class EnviarBoletaPorCorreoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $envioId) {}

    public function handle(BoletaService $boletas, BoletaPdf $pdf): void
    {
        $envio = BoletaEnvio::with('boleta')->find($this->envioId);
        if (! $envio || $envio->estado !== 'pendiente') {
            return;
        }

        // Con el mailer "log" el PDF y el sueldo terminarían en el log: fuera
        // de local/testing no se "envía" por ahí (mismo criterio que Reclutamiento).
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment(['local', 'testing'])) {
            $this->fallar($envio, 'El servidor no tiene configurado un servicio de correo.');

            return;
        }

        $boleta = $envio->boleta;
        if (! $boleta || ! $boleta->es_version_vigente || $boleta->estado !== 'pagada') {
            $this->fallar($envio, 'La boleta cambió antes del envío (ya no está pagada o se recalculó). Vuelve a enviarla.');

            return;
        }

        try {
            $boleta = $boletas->ver(Empresa::findOrFail($envio->empresa_id), $boleta);
            $nombre = trim(($boleta->colaborador->nombres ?? '').' '.($boleta->colaborador->apellidos ?? ''));
            $periodo = (string) $boleta->ciclo?->nombre;

            Mail::to($envio->email)->send(new BoletaMail(
                colaborador: $nombre,
                empresa: (string) $boleta->colaborador->empresa?->nombre_comercial,
                periodo: $periodo,
                pdf: $pdf->generar($boleta),
                nombreArchivo: 'boleta_'.Str::slug($periodo).'_'.Str::slug((string) $boleta->colaborador->numero_documento).'.pdf',
            ));

            $envio->update(['estado' => 'enviado', 'enviado_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            // Sin destinatario ni contenido en el log: solo el envío y el error.
            Log::error('Boleta por correo: error al enviar.', ['envio_id' => $envio->id, 'error' => $e->getMessage()]);
            $this->fallar($envio, 'No se pudo enviar el correo: '.Str::limit($e->getMessage(), 180));
        }
    }

    private function fallar(BoletaEnvio $envio, string $motivo): void
    {
        $envio->update(['estado' => 'fallido', 'error' => Str::limit($motivo, 250)]);
    }
}
