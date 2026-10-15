<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La liquidación deja de generarse al cesar: se crea aparte, en modo
 * asistido (propuesta del sistema, editable) o manual (sin data histórica).
 * Las liquidaciones existentes incluían la remuneración del mes y se
 * conservan tal cual, marcadas como "legado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->string('modo', 20)->default('asistido')->after('regimen_laboral_snapshot');
            $table->text('sustento')->nullable()->after('modo');
            // Datos de entrada del cálculo (sueldo, fecha de ingreso,
            // gratificación percibida, vacaciones gozadas...) con su origen:
            // 'sistema' o 'manual' cuando RR.HH. completó un dato faltante.
            $table->json('datos_calculo')->nullable()->after('sustento');
            $table->date('fecha_pago')->nullable()->after('referencia_pago');
            $table->string('medio_pago', 40)->nullable()->after('fecha_pago');
        });

        Schema::table('liquidacion_cese_conceptos', function (Blueprint $table) {
            $table->string('origen', 20)->default('calculado')->after('tipo');
            $table->decimal('monto_calculado', 12, 2)->nullable()->after('monto');
            $table->text('motivo_ajuste')->nullable()->after('formula_texto');
        });

        DB::table('liquidaciones_cese')->update(['modo' => 'legado']);
    }

    public function down(): void
    {
        Schema::table('liquidacion_cese_conceptos', function (Blueprint $table) {
            $table->dropColumn(['origen', 'monto_calculado', 'motivo_ajuste']);
        });
        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->dropColumn(['modo', 'sustento', 'datos_calculo', 'fecha_pago', 'medio_pago']);
        });
    }
};
