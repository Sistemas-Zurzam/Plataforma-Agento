<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $concepto = DB::table('conceptos_remuneracion')
                ->where('codigo', 'DESCUENTO_COMPRA_MERCADERIA')
                ->first(['id', 'codigo_plame']);

            // Una configuracion manual previa tiene prioridad.
            if (! $concepto || $concepto->codigo_plame !== null) {
                return;
            }

            DB::table('conceptos_remuneracion')
                ->where('id', $concepto->id)
                ->update(['codigo_plame' => '0706', 'updated_at' => now()]);

            DB::table('concepto_codigos_plame')->insert([
                'concepto_remuneracion_id' => $concepto->id,
                'codigo_plame' => '0706',
                'descripcion_sunat' => 'Otros descuentos no deducibles de la base imponible',
                'vigencia_desde' => now()->toDateString(),
                'actualizado_por_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('boleta_conceptos')
                ->where('concepto_id', $concepto->id)
                ->whereNull('concepto_definicion_id')
                ->whereNull('codigo_plame_snapshot')
                ->update(['codigo_plame_snapshot' => '0706']);
        });
    }

    public function down(): void
    {
        // No se revierte para no borrar una clasificacion ni snapshots que
        // ya pudieron utilizarse en una declaracion PLAME.
    }
};
