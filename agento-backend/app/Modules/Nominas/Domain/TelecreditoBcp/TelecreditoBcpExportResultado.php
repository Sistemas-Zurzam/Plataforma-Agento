<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

/**
 * Resultado de una exportación Telecrédito BCP — sin nada de HTTP (mismo
 * criterio que PlameExportResultado/AfpNetExportResultado, pero
 * completamente separado: nunca se comparte código entre integraciones).
 */
final readonly class TelecreditoBcpExportResultado
{
    public function __construct(
        public bool $listo,
        public ?string $codigo,
        public string $mensaje,
        public ?array $archivo, // {nombre: string, contenido: string}|null
        public array $validacion,
    ) {}

    public static function bloqueado(string $codigo, string $mensaje, array $validacion): self
    {
        return new self(false, $codigo, $mensaje, null, $validacion);
    }

    public static function generado(string $mensaje, array $archivo, array $validacion): self
    {
        return new self(true, null, $mensaje, $archivo, $validacion);
    }
}
