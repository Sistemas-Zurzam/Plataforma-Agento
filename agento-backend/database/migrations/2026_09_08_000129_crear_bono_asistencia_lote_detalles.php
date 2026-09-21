<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por colaborador dentro de un bono_asistencia_lote — snapshot de
 * lo que se exportó a Excel (columnas *_snapshot y las cuentas propuestas)
 * más lo que Livex devolvió al reimportar (columnas *_final, sufijo
 * "_livex"). Nunca se sobrescribe el snapshot original: así queda trazable
 * qué propuso el sistema vs. qué corrigió/aprobó Livex.
 *
 * documento_snapshot es la columna de match al reimportar el Excel (por
 * DNI, como ya usa ImportarAntecedentesHistoricosService) — se guarda aparte
 * de colaborador_id porque el número de documento de un colaborador podría
 * cambiar entre la exportación y el reimport, y el Excel que regresa Livex
 * solo trae DNI, no IDs internos de Agento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bono_asistencia_lote_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bono_asistencia_lote_id')->constrained('bono_asistencia_lotes')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();

            $table->string('documento_snapshot', 20);
            $table->string('colaborador_nombre_snapshot', 200);

            $table->unsignedSmallInteger('dias_falta_justificada');
            $table->unsignedSmallInteger('dias_falta_injustificada');
            $table->unsignedSmallInteger('tardanzas');

            $table->decimal('bono_base', 10, 2);
            $table->unsignedTinyInteger('porcentaje_propuesto');
            $table->decimal('monto_propuesto', 10, 2);

            // Lo que regresa Livex al reimportar el Excel — null hasta ese
            // momento (lote en estado 'borrador'/'exportado').
            $table->unsignedSmallInteger('dias_falta_injustificada_livex')->nullable();
            $table->unsignedSmallInteger('dias_falta_justificada_livex')->nullable();
            $table->boolean('meta_comercial_cumplida')->nullable();
            $table->boolean('aprobado')->nullable();
            $table->unsignedTinyInteger('porcentaje_final')->nullable();
            $table->decimal('monto_final', 10, 2)->nullable();
            $table->string('observacion_livex', 500)->nullable();

            // Referencia a la línea que este detalle generó al aplicarse —
            // evita duplicar el bono si el lote se aplica más de una vez por
            // error (ver BonoAsistenciaService::aplicar()). Nombre de FK
            // explícito y corto: el autogenerado
            // ("bono_asistencia_lote_detalles_colaborador_concepto_periodo_id_foreign")
            // supera los 64 caracteres que permite MySQL — mismo criterio ya
            // usado en concepto_definiciones_plame_unico.
            $table->foreignId('colaborador_concepto_periodo_id')->nullable();
            $table->foreign('colaborador_concepto_periodo_id', 'bono_asist_detalle_ccp_id_fk')
                ->references('id')->on('colaborador_conceptos_periodo')->nullOnDelete();

            $table->timestamps();

            $table->unique(['bono_asistencia_lote_id', 'colaborador_id'], 'bono_asistencia_detalles_lote_colaborador_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bono_asistencia_lote_detalles');
    }
};
