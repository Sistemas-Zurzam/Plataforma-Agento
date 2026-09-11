<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lote mensual del Bono de Asistencia (política Livex): el sistema propone
 * un porcentaje por colaborador según faltas/tardanzas del ciclo, se exporta
 * a Excel, Livex lo revisa/corrige/aprueba fuera del sistema y se reimporta
 * — recién ahí se aplica (escribe en colaborador_conceptos_periodo para que
 * el ciclo lo recoja en su próximo recálculo, ANTES de pagarlo).
 *
 * Mismo patrón borrador→...→aplicado que nomina_importaciones_historicas
 * (ver esa migración), pero con dos pasos intermedios propios de este
 * flujo de ida y vuelta con un externo: exportado (ya se generó el Excel
 * para enviar) y revisado (ya se reimportó el Excel que regresó Livex, con
 * sus correcciones y aprobaciones por colaborador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bono_asistencia_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->restrictOnDelete();

            $table->string('nombre', 150);
            $table->string('motivo', 255)->nullable();
            $table->string('estado', 20)->default('borrador'); // borrador|exportado|revisado|aplicado|anulado

            // Concepto con el que se paga el bono (BONO_NO_REMUNERATIVO en la
            // práctica) — igual que PlanillaComplementariaService::aplicarBonoPorAsistencia(),
            // BONIFICACION/BONO_NO_REMUNERATIVO exigen una clasificación PLAME concreta.
            $table->foreignId('concepto_id')->constrained('conceptos_remuneracion')->restrictOnDelete();
            $table->foreignId('concepto_definicion_id')->nullable()
                ->constrained('concepto_definiciones_plame')->nullOnDelete();

            $table->string('archivo_exportado_nombre', 255)->nullable();
            $table->timestamp('exportado_en')->nullable();
            $table->foreignId('exportado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('archivo_importado_nombre', 255)->nullable();
            $table->timestamp('revisado_en')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('aplicado_en')->nullable();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'ciclo_id', 'estado'], 'bono_asistencia_lotes_empresa_ciclo_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bono_asistencia_lotes');
    }
};
