<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in por empresa para "descanso semanal flexible automático" en
 * colaboradores rotativos: el primer día elegible sin marcaciones de la
 * semana se clasifica solo como descanso, los siguientes como falta,
 * siempre respetando permiso/feriado/trabajo real. Con este flag en false
 * (default) el comportamiento es exactamente el actual — cero inferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->boolean('descanso_flexible_automatico')->default(false)->after('activa');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('descanso_flexible_automatico');
        });
    }
};
