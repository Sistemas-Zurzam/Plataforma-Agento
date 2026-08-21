<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asignación PERSISTENTE de responsable por área (distinto de
     * asistencia_solicitudes_area.responsable_por, que solo registra quién
     * aprobó una solicitud puntual) — este es el responsable "por defecto"
     * de un área, visible desde Configuración → Empresas.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->foreignId('responsable_user_id')->nullable()->after('nombre')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_user_id');
        });
    }
};
