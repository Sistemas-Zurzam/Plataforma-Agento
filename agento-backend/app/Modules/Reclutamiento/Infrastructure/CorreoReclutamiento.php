<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envío de correos del módulo usando el mailer configurado del proyecto.
 *
 * Los correos de este módulo contienen códigos y enlaces de acceso. Con el
 * mailer "log" Laravel escribe el cuerpo completo en el log, así que fuera de
 * local/testing NO se envían por ese canal: se registra solo una advertencia
 * (sin destinatario ni contenido) y el método devuelve false para que la
 * operación lo informe honestamente.
 */
class CorreoReclutamiento
{
    public function enviar(string $destinatario, string $asunto, string $cuerpo): bool
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment(['local', 'testing'])) {
            Log::warning('Reclutamiento: correo no enviado porque el mailer configurado no entrega correos reales.', ['mailer' => $mailer]);

            return false;
        }

        try {
            Mail::raw($cuerpo, fn ($mensaje) => $mensaje->to($destinatario)->subject($asunto));

            return true;
        } catch (Throwable $e) {
            Log::error('Reclutamiento: error al enviar correo.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function urlPortal(string $ruta): string
    {
        return rtrim((string) config('reclutamiento.url_portal'), '/').'/'.ltrim($ruta, '/');
    }
}
