<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo único de conceptos de boleta. es_remunerativo_laboral y
     * afecta_renta_5ta son flags independientes a propósito — un concepto
     * puede ser no remunerativo para AFP/CTS/gratificación y aun así estar
     * afecto a renta de 5ta (art. 34° LIR vs. art. 19° Ley CTS). Nunca
     * derivar uno del otro.
     */
    public function up(): void
    {
        Schema::create('conceptos_remuneracion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('tipo'); // ingreso | egreso | aportacion
            $table->boolean('es_remunerativo_laboral')->default(false);
            $table->boolean('afecta_renta_5ta')->default(false);
            $table->boolean('afecta_afp')->default(false);
            $table->boolean('afecta_essalud')->default(false);
            $table->boolean('afecta_cts')->default(false);
            $table->boolean('afecta_gratificacion')->default(false);
            $table->boolean('afecta_vacaciones')->default(false);
            $table->string('codigo_plame')->nullable();
            $table->string('codigo_afpnet')->nullable();
            $table->unsignedTinyInteger('alerta_recurrencia_meses')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conceptos_remuneracion');
    }
};
