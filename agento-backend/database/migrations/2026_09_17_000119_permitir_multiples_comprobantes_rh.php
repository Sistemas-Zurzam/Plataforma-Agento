<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boleta_comprobantes_rh', function (Blueprint $table) {
            $table->dropUnique(['boleta_id']);
            $table->decimal('monto_total_servicio', 12, 2)->nullable()->after('fecha_pago');
            $table->unique(['boleta_id', 'serie', 'numero'], 'boleta_rh_comprobante_unico');
        });

        DB::table('boleta_comprobantes_rh')->whereNull('monto_total_servicio')->orderBy('id')->each(function (object $comprobante): void {
            $monto = DB::table('boleta_conceptos')
                ->join('conceptos_remuneracion', 'conceptos_remuneracion.id', '=', 'boleta_conceptos.concepto_id')
                ->where('boleta_conceptos.boleta_id', $comprobante->boleta_id)
                ->where('conceptos_remuneracion.codigo', 'HONORARIO_BRUTO')
                ->sum('boleta_conceptos.monto');
            DB::table('boleta_comprobantes_rh')->where('id', $comprobante->id)->update(['monto_total_servicio' => $monto]);
        });
    }

    public function down(): void
    {
        Schema::table('boleta_comprobantes_rh', function (Blueprint $table) {
            $table->dropUnique('boleta_rh_comprobante_unico');
            $table->dropColumn('monto_total_servicio');
            $table->unique('boleta_id');
        });
    }
};
