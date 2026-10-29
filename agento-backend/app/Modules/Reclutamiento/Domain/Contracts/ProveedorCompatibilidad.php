<?php

namespace App\Modules\Reclutamiento\Domain\Contracts;

/**
 * Proveedor externo que aporta EVIDENCIA de compatibilidad (resultado por
 * requisito y nivel por competencia). El puntaje siempre lo calcula
 * CalculadoraAts con la fórmula versionada: el proveedor no decide ni actúa.
 */
interface ProveedorCompatibilidad
{
    public function disponible(): bool;

    public function nombre(): string;

    /**
     * Devuelve requisitos (requisito_id => cumple|no_cumple|sin_informacion) y
     * competencias (nombre normalizado => nivel 1-5 o null).
     *
     * @param  array<string, mixed>  $perfil  requisitos y competencias del perfil vigente
     * @param  array<string, mixed>  $candidatura  snapshot declarado por el candidato
     * @return array{requisitos: array<int, string>, competencias: array<string, int|null>}
     */
    public function evaluar(array $perfil, array $candidatura): array;
}
