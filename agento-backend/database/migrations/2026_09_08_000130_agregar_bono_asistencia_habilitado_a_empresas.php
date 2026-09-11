<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in por empresa para la pestaña "Bono de Asistencia" en Gestión de
 * Remuneraciones. Es una política específica de Livex hoy, pero se deja
 * configurable por empresa por si otra empresa la necesita más adelante.
 * Con este flag en false (default) la pestaña no se muestra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->boolean('bono_asistencia_habilitado')->default(false)->after('descanso_flexible_automatico');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('bono_asistencia_habilitado');
        });
    }
};
