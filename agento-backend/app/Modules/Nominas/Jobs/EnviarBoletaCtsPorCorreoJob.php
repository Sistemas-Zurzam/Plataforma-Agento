<?php

namespace App\Modules\Nominas\Jobs;

use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsMail;
use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsPdf;
use App\Modules\Nominas\Models\BeneficioSocialEnvio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Genera la boleta de CTS de UN colaborador y la envía; el resultado queda en beneficio_social_envios. */
class EnviarBoletaCtsPorCorreoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $envioId) {}

    public function handle(BoletaCtsPdf $pdf): void
    {
        $envio = BeneficioSocialEnvio::with('detalle.beneficioSocial.empresa', 'detalle.colaborador', 'detalle.depositos')->find($this->envioId);
        if (! $envio || $envio->estado !== 'pendiente') {
            return;
        }

        // Con el mailer "log" el PDF terminaría en el log: fuera de local/testing no se "envía" por ahí.
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment(['local', 'testing'])) {
            $this->fallar($envio, 'El servidor no tiene configurado un servicio de correo.');

            return;
        }

        $detalle = $envio->detalle;
        $beneficio = $detalle?->beneficioSocial;
        if (! $beneficio || ! $beneficio->es_version_vigente) {
            $this->fallar($envio, 'La CTS se recalculó antes del envío. Vuelve a enviarla.');

            return;
        }

        try {
            $datos = $pdf->datos($beneficio, $detalle);
            Mail::to($envio->email)->send(new BoletaCtsMail(
                colaborador: $datos['trabajador']['nombre'],
                empresa: (string) $beneficio->empresa->nombre_comercial,
                periodo: $datos['periodo'],
                pdf: $pdf->generar($beneficio, collect([$detalle])),
                nombreArchivo: 'boleta-cts_'.$beneficio->tipo.'_'.$beneficio->anio.'_'.Str::slug((string) $detalle->colaborador->numero_documento).'.pdf',
            ));
            $envio->update(['estado' => 'enviado', 'enviado_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            Log::error('Boleta de CTS por correo: error al enviar.', ['envio_id' => $envio->id, 'error' => $e->getMessage()]);
            $this->fallar($envio, 'No se pudo enviar el correo: '.Str::limit($e->getMessage(), 180));
        }
    }

    private function fallar(BeneficioSocialEnvio $envio, string $motivo): void
    {
        $envio->update(['estado' => 'fallido', 'error' => Str::limit($motivo, 250)]);
    }
}
