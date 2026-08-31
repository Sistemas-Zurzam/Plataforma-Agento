<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las migraciones 106 y 107 no alcanzaron filas que el modal había
     * convertido a origen manual. El feriado nacional debe prevalecer sin
     * importar cómo se guardó anteriormente el calendario.
     */
    public function up(): void
    {
        DB::table('colaborador_calendario_dias')
            ->whereMonth('fecha', 8)
            ->whereDay('fecha', 6)
            ->whereYear('fecha', '>=', 2026)
            ->where('tipo', '!=', 'feriado')
            ->update([
                'tipo' => 'feriado',
                'origen' => 'feriado_automatico',
                'updated_at' => now(),
            ]);
    }

    public function down(): void {}
};
