<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Validation\ValidationException;

/**
 * Consulta de solo lectura para otros módulos: ¿alguna fecha del rango cae
 * en un ciclo remunerativo cerrado o pagado de la empresa? Mismo criterio
 * que CicloRemunerativoService::verificarPeriodoEditable() (cerrado/pagado).
 */
class CiclosCerrados
{
    public const ESTADOS_CERRADOS = ['cerrado', 'pagado'];

    public function cubreRango(int $empresaId, string $desde, string $hasta): bool
    {
        return CicloRemunerativo::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('estado', self::ESTADOS_CERRADOS)
            ->whereDate('fecha_inicio', '<=', $hasta)
            ->whereDate('fecha_fin', '>=', $desde)
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public function asegurarAbierto(int $empresaId, string $desde, string $hasta, string $campo = 'fecha'): void
    {
        if ($this->cubreRango($empresaId, $desde, $hasta)) {
            throw ValidationException::withMessages([
                $campo => ['La fecha pertenece a un ciclo remunerativo cerrado o pagado. Coordina cualquier ajuste con RR. HH.'],
            ]);
        }
    }
}
