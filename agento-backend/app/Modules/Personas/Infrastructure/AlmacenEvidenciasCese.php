<?php

namespace App\Modules\Personas\Infrastructure;

use App\Modules\Personas\Models\SolicitudCese;
use App\Modules\Personas\Models\SolicitudCeseEvidencia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidencias de solicitudes de cese en el disco privado 'local'
 * (storage/app/private). Nombre y ruta los genera el servidor; el nombre
 * original solo se conserva para mostrarlo. El tipo real se verifica por
 * contenido (firma de archivo + finfo), no solo por extensión.
 */
class AlmacenEvidenciasCese
{
    private const DISCO = 'local';

    /** @var array<string, string> mime real → extensión */
    private const TIPOS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /**
     * @return array{ruta: string, mime: string, sha256: string}
     */
    public function guardar(SolicitudCese $solicitud, UploadedFile $archivo, string $campo): array
    {
        $mime = $this->mimeReal($archivo);
        if (! $mime) {
            throw ValidationException::withMessages([$campo => ['El archivo no es un PDF, JPG o PNG válido.']]);
        }

        $directorio = "personas/solicitudes-cese/{$solicitud->empresa_id}/{$solicitud->id}";
        $nombre = Str::uuid().'.'.self::TIPOS[$mime];
        $ruta = $archivo->storeAs($directorio, $nombre, self::DISCO);

        return ['ruta' => $ruta, 'mime' => $mime, 'sha256' => hash_file('sha256', $archivo->getRealPath())];
    }

    public function eliminar(string $ruta): void
    {
        Storage::disk(self::DISCO)->delete($ruta);
    }

    public function descargar(SolicitudCeseEvidencia $evidencia): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISCO)->exists($evidencia->ruta), 404);

        return Storage::disk(self::DISCO)->download($evidencia->ruta, $evidencia->nombre_original, [
            'Content-Type' => $evidencia->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mimeReal(UploadedFile $archivo): ?string
    {
        $handle = fopen($archivo->getRealPath(), 'rb');
        $cabecera = $handle ? (string) fread($handle, 8) : '';
        if ($handle) {
            fclose($handle);
        }

        $porFirma = match (true) {
            str_starts_with($cabecera, '%PDF-') => 'application/pdf',
            str_starts_with($cabecera, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($cabecera, "\x89PNG\r\n\x1A\n") => 'image/png',
            default => null,
        };

        // Doble control: la regla `mimes` del Form Request ya validó el MIME
        // detectado por contenido (finfo); aquí además debe coincidir la
        // firma de bytes con ese MIME detectado.
        return $porFirma !== null && $porFirma === $archivo->getMimeType() ? $porFirma : null;
    }
}
