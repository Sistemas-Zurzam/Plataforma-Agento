<?php

namespace App\Modules\Nominas\Domain;

use RuntimeException;

/**
 * Punto de extensión: agregar un régimen con fórmula genuinamente distinta
 * (Agrario, Construcción Civil, Trabajo del Hogar, RH) significa crear una
 * clase nueva que implemente RegimenCalculator y sumar un case aquí — nunca
 * tocar PlanillaDependienteCalculator ni un if/else gigante en el servicio
 * de cálculo (Sección 20).
 */
class RegimenCalculatorFactory
{
    public static function paraRegimen(string $regimenLaboral): RegimenCalculator
    {
        return match ($regimenLaboral) {
            'General', 'Micro Empresa', 'Pequeña Empresa' => new PlanillaDependienteCalculator(),
            default => throw new RuntimeException(
                "El régimen laboral \"{$regimenLaboral}\" todavía no tiene un motor de cálculo implementado. ".
                'Regímenes soportados en este sprint: General, Micro Empresa, Pequeña Empresa.'
            ),
        };
    }
}
