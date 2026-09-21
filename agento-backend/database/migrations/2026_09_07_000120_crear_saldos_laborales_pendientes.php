<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 1 — préstamos, adelantos o descuentos con saldo pendiente
 * anteriores a agosto de 2026, para que una futura liquidación (Incremento
 * 2) pueda descontarlos sin que RR.HH. tenga que recordarlos manualmente.
 * Todavía NO se descuenta nada automáticamente de ninguna liquidación.
 *
 * `saldo_pendiente` es una columna GENERADA (virtual, no almacenada) igual
 * a `importe_original - importe_aplicado`, declarada UNSIGNED: en MySQL
 * esto hace que el propio motor rechace cualquier fila donde
 * `importe_aplicado` supere a `importe_original` (el resultado negativo no
 * cabe en una columna unsigned), sin necesitar un CHECK constraint ni un
 * evento de Eloquent. SQLite no aplica semántica "unsigned" de verdad — ver
 * el método `saldoEsCoherente()` del modelo y el informe de Incremento 1
 * para la limitación documentada en el entorno de pruebas.
 *
 * No se permite un `unique(importacion_detalle_id)` a secas porque la
 * columna es nullable (un saldo puede registrarse sin fila de origen); en
 * MySQL y SQLite un índice único con múltiples NULL los permite todos sin
 * problema, así que sigue impidiendo que DOS saldos reclamen la MISMA fila
 * de origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_laborales_pendientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('importacion_detalle_id')->nullable()
                ->unique()
                ->constrained('nomina_importacion_historica_detalles')->nullOnDelete();

            $table->date('fecha_ingreso_vinculo');
            $table->date('fecha_fin_vinculo')->nullable();

            $table->string('tipo', 20); // prestamo|adelanto|descuento|otro
            $table->string('descripcion', 255);

            $table->decimal('importe_original', 12, 2)->unsigned();
            $table->decimal('importe_aplicado', 12, 2)->unsigned()->default(0);

            $table->date('fecha_corte');
            $table->string('estado', 20)->default('borrador'); // borrador|aprobado|aplicado_parcial|aplicado|anulado
            $table->string('origen', 30)->default('excel_historico');
            $table->string('referencia_externa', 120)->nullable();
            $table->text('observaciones')->nullable();

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->timestamps();

            $table->decimal('saldo_pendiente', 12, 2)->unsigned()
                ->virtualAs('importe_original - importe_aplicado');

            $table->index(['empresa_id', 'estado'], 'saldo_laboral_pend_empresa_estado_idx');
            $table->index('colaborador_id', 'saldo_laboral_pend_colaborador_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_laborales_pendientes');
    }
};
