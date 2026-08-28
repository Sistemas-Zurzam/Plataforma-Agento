<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `colaboradores.banco` (string libre) se conserva sin tocar — lo siguen
 * leyendo/escribiendo ColaboradorXlsxReader, ColaboradorPlantillaGenerator,
 * ColaboradorResource y el formulario existente. `banco_id` es la
 * identidad CONFIABLE nueva que Telecrédito (y cualquier integración
 * bancaria futura) debe usar — se resuelve automáticamente desde `banco`
 * en ColaboradorService::crear()/actualizar(), nunca se le pide al usuario
 * que la llene dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->foreignId('banco_id')->nullable()->after('banco')
                ->constrained('bancos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banco_id');
        });
    }
};
