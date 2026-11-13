<?php

namespace App\Modules\Nominas\Infrastructure\Cts;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo con la boleta de CTS (hoja de liquidación del depósito) en PDF adjunto. */
class BoletaCtsMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $colaborador,
        public readonly string $empresa,
        public readonly string $periodo,
        private readonly string $pdf,
        private readonly string $nombreArchivo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Boleta de CTS {$this->periodo} — {$this->empresa}");
    }

    public function content(): Content
    {
        return new Content(text: 'nominas.correo-boleta-cts');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->nombreArchivo)->withMime('application/pdf')];
    }
}
