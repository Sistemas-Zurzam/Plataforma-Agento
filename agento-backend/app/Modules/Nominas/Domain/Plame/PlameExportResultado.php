<?php

namespace App\Modules\Nominas\Domain\Plame;

/**
 * Resultado de una operación de exportación PLAME — deliberadamente sin
 * nada de HTTP (Sección 70: los Generators/Service no dependen de
 * Response), para que PlameExportService se pueda probar aparte y el
 * Controller decida cómo responder (JSON de error vs. descarga de archivo).
 */
final readonly class PlameExportResultado
{
    /**
     * @param  array<int, array{nombre: string, contenido: string}>  $archivos
     */
    public function __construct(
        public bool $listo,
        public ?string $codigo,
        public string $mensaje,
        public array $archivos,
        public array $validacion,
    ) {}

    public static function bloqueado(string $codigo, string $mensaje, array $validacion): self
    {
        return new self(false, $codigo, $mensaje, [], $validacion);
    }

    public static function generado(string $mensaje, array $archivos, array $validacion): self
    {
        return new self(true, null, $mensaje, $archivos, $validacion);
    }
}
