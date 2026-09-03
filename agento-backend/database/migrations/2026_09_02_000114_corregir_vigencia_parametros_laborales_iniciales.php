<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $inicioDelAnio = now()->startOfYear()->toDateString();

        // Repara instalaciones que inicializaron los valores durante el año.
        // Se inserta historial (append-only) en lugar de modificar lo auditado.
        $iniciales = DB::table('parametro_laboral_valores')
            ->where('motivo', 'Inicialización de valores por defecto')
            ->whereDate('vigencia_desde', '>', $inicioDelAnio)
            ->get();

        foreach ($iniciales as $valor) {
            $yaCubreElAnio = DB::table('parametro_laboral_valores')
                ->where('empresa_id', $valor->empresa_id)
                ->where('definicion_id', $valor->definicion_id)
                ->where('regimen_laboral', $valor->regimen_laboral)
                ->whereDate('vigencia_desde', '<=', $inicioDelAnio)
                ->exists();

            if ($yaCubreElAnio) {
                continue;
            }

            DB::table('parametro_laboral_valores')->insert([
                'empresa_id' => $valor->empresa_id,
                'definicion_id' => $valor->definicion_id,
                'regimen_laboral' => $valor->regimen_laboral,
                'vigencia_desde' => $inicioDelAnio,
                'valor' => $valor->valor,
                'creado_por_id' => $valor->creado_por_id,
                'motivo' => 'Corrección de vigencia de valores por defecto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parametro_laboral_valores')
            ->where('motivo', 'Corrección de vigencia de valores por defecto')
            ->delete();
    }
};
