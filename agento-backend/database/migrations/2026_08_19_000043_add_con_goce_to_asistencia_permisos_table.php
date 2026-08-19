<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Falta este dato para que Remuneraciones pueda distinguir permiso CON
     * goce (no descuenta el básico) de SIN goce (sí lo descuenta, Sección
     * 2.3/33 de la especificación de nómina) — hasta ahora Asistencia no
     * necesitaba esa distinción para su propio cálculo. Cambio puramente
     * aditivo: no toca ninguna regla ni columna existente de Asistencia.
     */
    public function up(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->boolean('con_goce')->default(true)->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropColumn('con_goce');
        });
    }
};
