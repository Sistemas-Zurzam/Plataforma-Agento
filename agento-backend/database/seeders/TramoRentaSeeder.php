<?php

namespace Database\Seeders;

use App\Modules\Nominas\Models\TramoRenta;
use Illuminate\Database\Seeder;

class TramoRentaSeeder extends Seeder
{
    /**
     * Tramos progresivos de renta de 5ta vigentes desde 2015 (Ley N° 30296)
     * — valores de referencia agosto 2026 (Sección 4.5 de la especificación).
     * Solo insertar si no existe ya un set con esa vigencia_desde.
     */
    public function run(): void
    {
        $vigenciaDesde = '2026-01-01';

        if (TramoRenta::where('categoria', 'quinta')->where('vigencia_desde', $vigenciaDesde)->exists()) {
            return;
        }

        $tramos = [
            ['orden' => 1, 'limite_inferior_uit' => 0, 'limite_superior_uit' => 5, 'tasa_porcentaje' => 8],
            ['orden' => 2, 'limite_inferior_uit' => 5, 'limite_superior_uit' => 20, 'tasa_porcentaje' => 14],
            ['orden' => 3, 'limite_inferior_uit' => 20, 'limite_superior_uit' => 35, 'tasa_porcentaje' => 17],
            ['orden' => 4, 'limite_inferior_uit' => 35, 'limite_superior_uit' => 45, 'tasa_porcentaje' => 20],
            ['orden' => 5, 'limite_inferior_uit' => 45, 'limite_superior_uit' => null, 'tasa_porcentaje' => 30],
        ];

        foreach ($tramos as $tramo) {
            TramoRenta::create([
                'categoria' => 'quinta',
                'vigencia_desde' => $vigenciaDesde,
                ...$tramo,
            ]);
        }
    }
}
