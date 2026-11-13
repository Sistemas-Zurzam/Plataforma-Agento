<?php

namespace App\Modules\Auditoria\Support;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Deja un payload apto para guardarse en la bitácora: enmascara claves
 * sensibles, reemplaza archivos por su metadato y acota tamaños.
 */
class SanitizadorAuditoria
{
    private const MASCARA = '[OCULTO]';

    private const MAX_CADENA = 500;

    private const MAX_PROFUNDIDAD = 5;

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>|null
     */
    public function payload(array $datos): ?array
    {
        if ($datos === []) {
            return null;
        }

        $limpio = $this->limpiar($datos, 0);
        $json = json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json !== false && strlen($json) > (int) config('auditoria.max_payload_bytes')) {
            return ['_truncado' => true, '_claves' => array_slice(array_keys($limpio), 0, 40)];
        }

        return $limpio;
    }

    public function esSensible(string $clave): bool
    {
        $clave = Str::lower($clave);

        foreach (config('auditoria.claves_sensibles', []) as $fragmento) {
            if (str_contains($clave, $fragmento)) {
                return true;
            }
        }

        return false;
    }

    public function valor(string $clave, mixed $valor): mixed
    {
        return $this->esSensible($clave) ? self::MASCARA : $this->escalar($valor, 0);
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    private function limpiar(array $datos, int $nivel): array
    {
        $salida = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && $this->esSensible($clave)) {
                $salida[$clave] = self::MASCARA;

                continue;
            }

            $salida[$clave] = $this->escalar($valor, $nivel);
        }

        return $salida;
    }

    private function escalar(mixed $valor, int $nivel): mixed
    {
        if ($valor instanceof UploadedFile) {
            return ['_archivo' => $valor->getClientOriginalName(), 'bytes' => $valor->getSize()];
        }

        if (is_array($valor)) {
            return $nivel >= self::MAX_PROFUNDIDAD ? '[...]' : $this->limpiar($valor, $nivel + 1);
        }

        if (is_string($valor)) {
            return mb_strlen($valor) > self::MAX_CADENA ? mb_substr($valor, 0, self::MAX_CADENA).'...' : $valor;
        }

        if (is_scalar($valor) || $valor === null) {
            return $valor;
        }

        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return '['.get_debug_type($valor).']';
    }
}
