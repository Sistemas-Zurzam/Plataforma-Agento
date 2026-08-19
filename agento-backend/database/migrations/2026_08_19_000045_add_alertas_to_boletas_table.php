<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persiste las alertas REALES que se activaron en este cálculo puntual
     * (ej. piso legal de EsSalud) — nunca un badge estático en el frontend
     * que afirme que algo aplicó cuando no aplicó para este colaborador en
     * este período (Sección 2.6 del encargo). Aditivo, no afecta boletas
     * previas (quedan con alertas = null, se interpreta como "sin alertas").
     */
    public function up(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->json('alertas')->nullable()->after('snapshot_reglas_version');
        });
    }

    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn('alertas');
        });
    }
};
