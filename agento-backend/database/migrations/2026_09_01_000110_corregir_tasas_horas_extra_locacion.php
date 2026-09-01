<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $valores = [
            'horas_extra_limite_25_h' => 2,
            'horas_extra_limite_35_h' => 6,
            'horas_extra_tasa_x25' => 1.25,
            'horas_extra_tasa_x35' => 1.35,
            'horas_extra_tasa_nocturna' => 2,
        ];

        foreach ($valores as $clave => $valor) {
            $definicionId = DB::table('parametro_laboral_definiciones')->where('clave', $clave)->value('id');

            if (! $definicionId) {
                continue;
            }

            DB::table('parametro_laboral_valores')
                ->where('regimen_laboral', 'Locacion de Servicios')
                ->where('definicion_id', $definicionId)
                ->where('valor', 0)
                ->update(['valor' => $valor, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // No se revierte: una tasa contractual positiva pudo ser utilizada
        // por boletas recalculadas y volverla a cero las subpagarÃ­a.
    }
};
