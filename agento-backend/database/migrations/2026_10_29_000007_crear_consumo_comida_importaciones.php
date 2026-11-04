<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos por consumo de comida importados desde Excel (Nóminas).
 *
 * archivo → importación → detalles (un consumo por fila) → aplicaciones
 * (un descuento por colaborador, vinculado al ColaboradorConceptoPeriodo que
 * lee el motor y a la versión de boleta que lo incorporó o retiró).
 *
 * referencia_activa solo tiene valor mientras la importación está aplicada:
 * el índice único (empresa, sistema de origen, referencia) impide aplicar dos
 * veces el mismo consumo y libera la referencia al anular. MySQL admite
 * varios NULL en un índice único.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Un primer intento falló en MySQL a mitad de camino (nombre de
        // clave foránea de más de 64 caracteres) y, como el DDL de MySQL no
        // es transaccional, dejó tablas creadas sin registrar la migración.
        // Estas tablas solo las crea esta migración y no pudieron recibir
        // datos, así que se eliminan antes de crearlas de nuevo.
        $this->down();

        Schema::create('consumo_comida_importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->cascadeOnDelete();
            $table->string('sistema_origen', 60);
            $table->string('archivo_nombre_original', 255);
            $table->string('archivo_ruta', 255);
            $table->char('archivo_hash', 64);
            $table->char('contenido_hash', 64);
            $table->string('estado', 20);
            $table->json('errores_generales')->nullable();
            $table->unsignedInteger('filas_totales')->default(0);
            $table->unsignedInteger('filas_con_error')->default(0);
            $table->unsignedInteger('filas_posible_duplicado')->default(0);
            $table->unsignedInteger('colaboradores_total')->default(0);
            $table->decimal('monto_total', 12, 2)->default(0);
            // Nombres explícitos y cortos: MySQL admite hasta 64 caracteres.
            $table->foreignId('reemplaza_importacion_id')->nullable()->constrained('consumo_comida_importaciones', 'id', 'cc_importaciones_reemplaza_fk')->nullOnDelete();
            $table->foreignId('importado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aplicado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'ciclo_id', 'estado'], 'cc_importaciones_empresa_ciclo_estado_idx');
            $table->index(['ciclo_id', 'archivo_hash']);
            $table->index(['ciclo_id', 'contenido_hash']);
        });

        Schema::create('consumo_comida_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->constrained('consumo_comida_importaciones')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedInteger('fila');
            $table->string('documento', 30)->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('colaborador_nombre_snapshot', 255)->nullable();
            $table->date('fecha_consumo')->nullable();
            $table->string('concepto', 150)->nullable();
            $table->string('sistema_origen', 60);
            $table->string('referencia', 100)->nullable();
            $table->string('referencia_activa', 100)->nullable();
            $table->decimal('monto', 12, 2)->nullable();
            $table->json('valores_originales');
            $table->string('estado', 20);
            $table->json('errores')->nullable();
            $table->json('errores_formato')->nullable();
            $table->foreignId('duplicado_admitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('duplicado_admitido_at')->nullable();
            $table->text('duplicado_motivo')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sistema_origen', 'referencia_activa'], 'consumo_comida_referencia_activa_unique');
            $table->index(['importacion_id', 'fila']);
            $table->index(['empresa_id', 'documento', 'fecha_consumo'], 'cc_detalles_empresa_documento_fecha_idx');
        });

        Schema::create('consumo_comida_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->constrained('consumo_comida_importaciones')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->unsignedInteger('consumos');
            $table->foreignId('colaborador_concepto_periodo_id')->nullable()->constrained('colaborador_conceptos_periodo', 'id', 'cc_aplicaciones_concepto_periodo_fk')->nullOnDelete();
            $table->string('estado', 20);
            $table->timestamp('aplicado_at');
            $table->timestamp('anulado_at')->nullable();
            // Versión de boleta que incorporó el descuento y la que lo retiró
            // tras la anulación (BoletaService::calcularBoletaColaborador()).
            $table->foreignId('boleta_id')->nullable()->constrained('boletas')->nullOnDelete();
            $table->foreignId('boleta_retiro_id')->nullable()->constrained('boletas')->nullOnDelete();
            $table->timestamps();

            $table->unique(['importacion_id', 'colaborador_id'], 'cc_aplicaciones_importacion_colaborador_unique');
            $table->index(['ciclo_id', 'colaborador_id', 'estado'], 'cc_aplicaciones_ciclo_colaborador_estado_idx');
        });

        Schema::create('consumo_comida_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->constrained('consumo_comida_importaciones')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 40);
            $table->text('motivo')->nullable();
            $table->json('datos')->nullable();
            $table->timestamps();

            $table->index(['importacion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_comida_eventos');
        Schema::dropIfExists('consumo_comida_aplicaciones');
        Schema::dropIfExists('consumo_comida_detalles');
        Schema::dropIfExists('consumo_comida_importaciones');
    }
};
