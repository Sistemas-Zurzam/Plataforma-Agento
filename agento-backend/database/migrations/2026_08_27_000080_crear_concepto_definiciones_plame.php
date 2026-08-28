<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BONIFICACION y BONO_NO_REMUNERATIVO son demasiado genéricos para un
     * único código PLAME (Tabla 22 tiene ~14 códigos de bonificación
     * distintos, y "Otros Conceptos" 1001-1020 son de libre definición) —
     * esta tabla permite que RR.HH. defina de antemano clasificaciones
     * concretas reutilizables ("Bono de productividad", "Bono por cierre
     * de campaña"), cada una con su propio código PLAME explícito. El
     * concepto motor (BONIFICACION/BONO_NO_REMUNERATIVO) sigue existiendo
     * sin cambios — esto NO lo reemplaza, solo lo especializa cuando se
     * usa.
     */
    public function up(): void
    {
        // dropIfExists defensivo: el intento anterior de esta misma
        // migración pudo haber creado la tabla (MySQL no revierte DDL) y
        // fallar después en el índice único por nombre autogenerado
        // demasiado largo — la tabla es nueva y siempre vacía en ese punto,
        // no hay riesgo de perder datos.
        Schema::dropIfExists('concepto_definiciones_plame');

        Schema::create('concepto_definiciones_plame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concepto_remuneracion_id')->constrained('conceptos_remuneracion')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('codigo_plame', 4);
            $table->string('descripcion_sunat')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Nombre explícito y corto: el autogenerado
            // ("concepto_definiciones_plame_concepto_remuneracion_id_nombre_unique")
            // supera los 64 caracteres que permite MySQL.
            $table->unique(['concepto_remuneracion_id', 'nombre'], 'concepto_definiciones_plame_unico');
        });

        // El período ad-hoc (comisión/bono) debe poder referenciar cuál
        // definición concreta se usó, para que el snapshot de la boleta
        // conserve la clasificación específica en vez de la genérica.
        Schema::table('colaborador_conceptos_periodo', function (Blueprint $table) {
            $table->foreignId('concepto_definicion_id')->nullable()->after('concepto_id')
                ->constrained('concepto_definiciones_plame')->nullOnDelete();
        });

        // Snapshot: la boleta ya calculada conserva EXACTAMENTE qué
        // definición se usó, sin depender de que la clasificación siga
        // existiendo/activa después (mismo criterio que codigo_plame_snapshot).
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->foreignId('concepto_definicion_id')->nullable()->after('codigo_plame_snapshot')
                ->constrained('concepto_definiciones_plame')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concepto_definicion_id');
        });

        Schema::table('colaborador_conceptos_periodo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concepto_definicion_id');
        });

        Schema::dropIfExists('concepto_definiciones_plame');
    }
};
