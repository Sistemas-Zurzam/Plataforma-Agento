<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * categoria_trabajador se agrego a las condiciones historicas despues
     * de que muchas filas ya existian. La ficha actual si conserva el dato,
     * por lo que se usa para completar exclusivamente los NULL heredados.
     * La fecha de vigencia y cualquier categoria ya registrada no cambian.
     */
    public function up(): void
    {
        DB::table('colaboradores')
            ->where('tipo_trabajador', 'trabajador')
            ->whereIn('categoria_trabajador', ['empleado', 'obrero'])
            ->select(['id', 'categoria_trabajador'])
            ->orderBy('id')
            ->each(function (object $colaborador): void {
                DB::table('colaborador_condiciones_laborales')
                    ->where('colaborador_id', $colaborador->id)
                    ->whereNull('categoria_trabajador')
                    ->update([
                        'categoria_trabajador' => $colaborador->categoria_trabajador,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // No se revierte: no se puede distinguir de forma segura una
        // categoria completada de otra confirmada luego por RR.HH.
    }
};
