<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CV y documentos de candidatos en almacenamiento PRIVADO (disco local por
 * defecto: storage/app/private). Nunca se generan URLs públicas: se entregan
 * mediante endpoints autorizados.
 */
class AlmacenDocumentosCandidato
{
    public function guardarCv(int $empresaId, int $candidatoId, UploadedFile $archivo): DocumentoCandidato
    {
        $this->validarPdf($archivo);

        $disco = config('reclutamiento.cv.disco', 'local');
        $ruta = $archivo->storeAs("reclutamiento/{$empresaId}/candidatos/{$candidatoId}", Str::uuid().'.pdf', $disco);

        return DocumentoCandidato::create([
            'empresa_id' => $empresaId,
            'candidato_id' => $candidatoId,
            'tipo' => 'cv',
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 190, ''),
            'mime' => 'application/pdf',
            'tamano' => $archivo->getSize(),
            'sha256' => hash_file('sha256', $archivo->getRealPath()),
        ]);
    }

    public function descargar(DocumentoCandidato $documento): StreamedResponse
    {
        abort_if($documento->eliminado_at || ! $documento->ruta || ! Storage::disk($documento->disco)->exists($documento->ruta), 404, 'El archivo no está disponible.');

        return Storage::disk($documento->disco)->response($documento->ruta, $documento->nombre_original, [
            'Content-Type' => $documento->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function eliminarArchivo(DocumentoCandidato $documento): void
    {
        if ($documento->ruta) {
            Storage::disk($documento->disco)->delete($documento->ruta);
        }

        $documento->forceFill(['ruta' => null, 'eliminado_at' => now()])->save();
    }

    /**
     * Valida el tipo REAL del archivo (firma %PDF- y MIME detectado por
     * contenido), no solo la extensión o el MIME declarado por el cliente.
     */
    private function validarPdf(UploadedFile $archivo): void
    {
        $maxBytes = (int) config('reclutamiento.cv.max_kb', 5120) * 1024;
        $handle = fopen($archivo->getRealPath(), 'rb');
        $cabecera = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        $mimeReal = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());

        if ($archivo->getSize() > $maxBytes || $cabecera !== '%PDF-' || $mimeReal !== 'application/pdf') {
            throw ValidationException::withMessages([
                'cv' => 'El CV debe ser un archivo PDF válido de hasta '.intdiv($maxBytes, 1024 * 1024).' MB.',
            ]);
        }
    }
}
