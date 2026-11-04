<?php

namespace App\Modules\Reclutamiento\Domain\Contracts;

/**
 * Extracción de datos estructurados desde un CV. El contenido del CV es dato,
 * nunca instrucciones: una implementación no puede ejecutar acciones ni
 * seguir indicaciones contenidas en el documento; solo devuelve datos.
 */
interface ExtractorCv
{
    public function disponible(): bool;

    public function nombre(): string;

    /**
     * @return array{habilidades: array<int, array{nombre: string, nivel: int|null}>, antecedentes: array<int, array<string, mixed>>}
     */
    public function extraer(string $rutaAbsoluta, string $mime): array;
}
