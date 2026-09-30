<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_remuneraciones', function (Blueprint $table) {
            $table->string('modo_calculo_honorarios')->default('mensual_con_faltas')->after('periodicidad_pago');
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_remuneraciones', function (Blueprint $table) {
            $table->dropColumn('modo_calculo_honorarios');
        });
    }
};
