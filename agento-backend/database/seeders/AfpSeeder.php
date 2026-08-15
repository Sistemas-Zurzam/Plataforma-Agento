<?php

namespace Database\Seeders;

use App\Modules\Configuracion\Models\Afp;
use Illuminate\Database\Seeder;

class AfpSeeder extends Seeder
{
    /**
     * Catálogo fijo de las 4 AFP que operan en Perú (definido por código,
     * igual que Permission/ParametroLaboralDefinicion).
     */
    public function run(): void
    {
        $afps = [
            ['clave' => 'prima', 'nombre' => 'Prima AFP'],
            ['clave' => 'profuturo', 'nombre' => 'Profuturo'],
            ['clave' => 'integra', 'nombre' => 'Integra'],
            ['clave' => 'habitat', 'nombre' => 'Habitat'],
        ];

        foreach ($afps as $afp) {
            Afp::firstOrCreate(['clave' => $afp['clave']], $afp);
        }
    }
}
