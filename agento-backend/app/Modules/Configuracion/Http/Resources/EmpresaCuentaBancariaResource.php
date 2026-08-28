<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nunca expone el número de cuenta completo (Sección 41 del encargo
 * Telecrédito) — enmascarado a los últimos 4 dígitos, mismo criterio que
 * pediste para el frontend: "*********5056".
 */
class EmpresaCuentaBancariaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'banco' => [
                'id' => $this->banco?->id,
                'codigo' => $this->banco?->codigo,
                'nombre' => $this->banco?->nombre,
            ],
            'tipo_cuenta' => $this->tipo_cuenta,
            'moneda' => $this->moneda,
            'numero_cuenta_enmascarado' => self::enmascarar($this->numero_cuenta),
            'uso' => $this->uso,
            'es_predeterminada' => $this->es_predeterminada,
            'activo' => $this->activo,
        ];
    }

    private static function enmascarar(?string $numero): ?string
    {
        if (blank($numero)) {
            return null;
        }

        $ultimos = substr($numero, -4);

        return str_repeat('*', max(0, strlen($numero) - 4)).$ultimos;
    }
}
