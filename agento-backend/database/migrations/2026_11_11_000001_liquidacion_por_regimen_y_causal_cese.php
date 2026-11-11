<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidación multirrégimen:
 *  - colaboradores.causal_cese: causal tipificada (TUO D.Leg. 728, art. 16).
 *    motivo_cese se conserva tal cual: los ceses existentes quedan con su
 *    texto histórico y causal NULL ("sin tipificar").
 *  - liquidaciones_cese: régimen aplicado por período, validaciones
 *    laborales pendientes/resueltas, bases variables por beneficio, versión
 *    de parámetros usada y aporte patronal (fuera del neto). Todo nullable:
 *    las liquidaciones ya emitidas no se recalculan ni se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('causal_cese', 40)->nullable()->after('motivo_cese');
        });

        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->string('causal_cese', 40)->nullable()->after('motivo_cese');
            $table->json('regimen_tramos')->nullable()->after('regimen_laboral_snapshot');
            $table->json('validaciones')->nullable()->after('datos_calculo');
            $table->json('bases_variables')->nullable()->after('validaciones');
            $table->string('parametros_version', 80)->nullable()->after('bases_variables');
            $table->decimal('total_aportaciones', 12, 2)->default(0)->after('total_egresos');
        });
    }

    public function down(): void
    {
        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->dropColumn(['causal_cese', 'regimen_tramos', 'validaciones', 'bases_variables', 'parametros_version', 'total_aportaciones']);
        });

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('causal_cese');
        });
    }
};
