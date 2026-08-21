<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de inscripción REMYPE (ya existía el toggle `inscrita_remype`,
     * faltaban estos dos) y el seguro de salud a cargo del empleador
     * (EsSalud | SIS) — solo aplica a régimen dependiente; un locador de
     * servicios (Locacion de Servicios) no tiene aporte de salud a cargo
     * del empleador, así que ese régimen simplemente no usa esta columna
     * (el frontend la muestra fija en "No aplica").
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->date('fecha_inscripcion_remype')->nullable()->after('inscrita_remype');
            $table->string('numero_registro_remype')->nullable()->after('fecha_inscripcion_remype');
            $table->string('seguro_salud')->default('essalud')->after('numero_registro_remype'); // essalud | sis
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['fecha_inscripcion_remype', 'numero_registro_remype', 'seguro_salud']);
        });
    }
};
