<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_horas_extra', function (Blueprint $table) {
            $table->dropForeign(['resultado_diario_id']);
            $table->unsignedBigInteger('resultado_diario_id')->nullable()->change();
            $table->string('origen', 20)->default('asistencia')->after('resultado_diario_id');
            $table->foreign('resultado_diario_id')->references('id')->on('asistencia_resultados_diarios')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('asistencia_horas_extra')->whereNull('resultado_diario_id')->exists()) {
            throw new \RuntimeException('No se puede revertir: existen horas extra manuales sin resultado de asistencia asociado.');
        }

        Schema::table('asistencia_horas_extra', function (Blueprint $table) {
            $table->dropForeign(['resultado_diario_id']);
            $table->dropColumn('origen');
            $table->unsignedBigInteger('resultado_diario_id')->nullable(false)->change();
            $table->foreign('resultado_diario_id')->references('id')->on('asistencia_resultados_diarios')->cascadeOnDelete();
        });
    }
};
