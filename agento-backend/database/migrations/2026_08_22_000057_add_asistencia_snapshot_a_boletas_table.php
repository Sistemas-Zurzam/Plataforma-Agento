<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            // Sin estas columnas, un colaborador sin asistencia procesada
            // aparecía indistinguible de uno con asistencia perfecta (0
            // faltas, 0 minutos de tardanza) — ver CalcularBoletaColaborador.
            $table->boolean('asistencia_procesada')->default(true)->after('dias_pagados');
            $table->decimal('dias_falta', 5, 2)->nullable()->after('asistencia_procesada');
            $table->unsignedInteger('minutos_tardanza')->nullable()->after('dias_falta');
        });
    }

    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn(['asistencia_procesada', 'dias_falta', 'minutos_tardanza']);
        });
    }
};
