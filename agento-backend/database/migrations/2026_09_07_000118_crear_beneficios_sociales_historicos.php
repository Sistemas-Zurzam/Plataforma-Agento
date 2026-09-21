<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 1 — gratificaciones/CTS pagadas o depositadas FUERA de Agento
 * (antes de agosto de 2026), aprobadas por RR.HH. tras conciliar el Excel.
 *
 * Deliberadamente separada de `beneficios_sociales`/`beneficio_social_detalles`:
 * esa tabla existente SIEMPRE deriva su monto sumando `boleta_conceptos` de
 * boletas ya calculadas en Agento (ver BeneficioSocialService, regla de oro
 * documentada en su docblock) — insertar ahí una fila "manual" rompería esa
 * invariante. `LiquidacionCeseService` (Incremento 2) leerá ambas fuentes
 * sin que ninguna de las dos cambie su comportamiento actual.
 *
 * No se modificó ni se modificará en este incremento: BeneficioSocialService,
 * LiquidacionCeseService, ni las tablas beneficios_sociales/beneficio_social_detalles.
 *
 * `fecha_ingreso_vinculo` es la clave de vínculo laboral (junto con
 * empresa_id + colaborador_id) — permite distinguir antecedentes de una
 * recontratación sin necesitar todavía una tabla de vínculos laborales
 * dedicada (ver nota en el informe de Incremento 1: crear
 * `colaborador_vinculos_laborales` ampliaría el alcance y no se implementa
 * aquí).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('importacion_detalle_id')->nullable()
                ->constrained('nomina_importacion_historica_detalles')->nullOnDelete();

            // gratificacion_julio|gratificacion_diciembre|cts_mayo|cts_noviembre|gratificacion_trunca|cts_trunca
            $table->string('tipo', 30);
            $table->unsignedSmallInteger('anio');
            $table->string('periodo', 20); // etiqueta corta, ej. "2026-S1" — nunca nula (forma parte de la unicidad)
            $table->date('fecha_periodo_inicio');
            $table->date('fecha_periodo_fin');
            $table->date('fecha_pago_deposito')->nullable();

            $table->decimal('importe_bruto', 12, 2)->unsigned();
            $table->decimal('importe_pagado', 12, 2)->unsigned()->nullable();

            // borrador|aprobado|pagado|depositado|anulado — distingue explícitamente
            // cálculo/aprobación (sin desembolso confirmado) de pago/depósito real.
            $table->string('estado', 20)->default('borrador');

            $table->date('fecha_ingreso_vinculo');
            $table->date('fecha_fin_vinculo')->nullable();
            $table->date('fecha_corte');

            $table->string('origen', 30)->default('excel_historico');
            $table->string('referencia_externa', 120)->nullable();
            $table->text('observaciones')->nullable();

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->timestamps();

            // Un mismo vínculo laboral no puede tener dos veces el mismo
            // beneficio del mismo tipo/año/periodo. No se excluye `anulado`
            // de este índice a propósito (ver informe, sección "Diferencias
            // respecto a la propuesta"): si una fila se anula por error de
            // digitación, la corrección para la MISMA combinación exacta
            // queda como decisión pendiente para el servicio de Incremento 2
            // (por ejemplo, editar la fila anulada en vez de crear una
            // nueva) — este incremento no la resuelve.
            $table->unique(
                ['empresa_id', 'colaborador_id', 'fecha_ingreso_vinculo', 'tipo', 'anio', 'periodo'],
                'beneficio_social_hist_vinculo_periodo_unique'
            );
            $table->index('importacion_detalle_id', 'beneficio_social_hist_import_detalle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficios_sociales_historicos');
    }
};
