<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 1 del módulo de antecedentes laborales históricos (Nóminas) —
 * ver DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md. Esta tabla representa cada
 * archivo Excel histórico cargado como un lote (borrador → validado →
 * aprobado → aplicado, o anulado en cualquier punto antes de aplicado).
 *
 * Puramente aditiva: no toca ninguna tabla existente. No se importa el
 * Excel real ni se consume este lote desde LiquidacionCeseService en este
 * incremento — eso es Incremento 2.
 *
 * Idempotencia real de "no aplicar el mismo archivo dos veces": la columna
 * generada `archivo_hash_aplicado` solo replica `archivo_hash` cuando
 * `estado = 'aplicado'`; el índice único va sobre esa columna, no sobre
 * `archivo_hash` directo. Un unique(empresa_id, archivo_hash) llano
 * bloquearía reintentos legítimos (un borrador fallido que se corrige y se
 * vuelve a subir, o una reimportación tras anular) — la regla de negocio
 * real es "no aplicar dos veces", no "no subir dos veces el mismo archivo".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_importaciones_historicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();

            $table->string('archivo_nombre_original', 255);
            $table->string('archivo_hash', 64); // SHA-256 hexadecimal del archivo
            $table->date('fecha_corte');

            $table->string('estado', 20)->default('borrador'); // borrador|validado|aprobado|aplicado|anulado

            $table->unsignedInteger('filas_totales')->default(0);
            $table->unsignedInteger('filas_validas')->default(0);
            $table->unsignedInteger('filas_observadas')->default(0);
            $table->unsignedInteger('filas_con_errores')->default(0);
            $table->unsignedInteger('filas_aplicadas')->default(0);

            $table->json('metadatos')->nullable();
            $table->json('resumen_errores')->nullable();

            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cargado_at')->nullable();
            $table->foreignId('validado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validado_at')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aplicado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->timestamps();

            // Columna generada (STORED): solo "existe" cuando el lote ya fue
            // aplicado. Ver docblock de la clase.
            $table->string('archivo_hash_aplicado', 64)->nullable()
                ->storedAs("CASE WHEN estado = 'aplicado' THEN archivo_hash ELSE NULL END");

            $table->index(['empresa_id', 'archivo_hash'], 'nomina_import_hist_empresa_hash_idx');
            $table->index(['empresa_id', 'estado'], 'nomina_import_hist_empresa_estado_idx');
            $table->unique(['empresa_id', 'archivo_hash_aplicado'], 'nomina_import_hist_empresa_hash_aplicado_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_importaciones_historicas');
    }
};
