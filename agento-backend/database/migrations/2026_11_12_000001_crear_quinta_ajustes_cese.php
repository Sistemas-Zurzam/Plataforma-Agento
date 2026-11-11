<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste final de quinta categoría del mes del cese (Reglamento LIR art. 41
 * inc. d): un registro por cese con el documento que lo aplica (liquidación o
 * boleta del mes del cese), el estado y cada aplicación con su desglose. El
 * índice único evita dos registros para el mismo cese cuando liquidación y
 * boleta se calculan a la vez.
 *
 * DEVOLUCION_RENTA_5TA: exceso retenido devuelto al cese (art. 42 inc. a);
 * la boleta necesita el concepto en el catálogo para poder registrarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quinta_ajustes_cese', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->date('fecha_cese');
            $table->unsignedSmallInteger('anio');
            // 'liquidacion' | 'boleta': documento que aplica el ajuste final.
            $table->string('documento_asignado', 20);
            // 'pendiente' (el documento asignado aún no se emite) | 'aplicado'.
            $table->string('estado', 20)->default('pendiente');
            $table->decimal('otros_empleadores_gravado', 12, 2)->default(0);
            $table->decimal('otros_empleadores_retenido', 12, 2)->default(0);
            // {liquidacion|boleta: {id, version, monto, estado, desglose, registrado_at}}
            $table->json('aplicaciones')->nullable();
            $table->timestamps();
            $table->unique(['colaborador_id', 'fecha_cese'], 'quinta_ajuste_cese_unico');
        });

        ConceptoRemuneracion::query()->firstOrCreate(['codigo' => 'DEVOLUCION_RENTA_5TA'], [
            'nombre' => 'Devolución de retención de 5ta en exceso', 'tipo' => 'ingreso',
            'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => false, 'afecta_afp' => false, 'afecta_essalud' => false,
            'afecta_cts' => false, 'afecta_gratificacion' => false, 'afecta_vacaciones' => false, 'activo' => true,
            'sunat_motivo_estado' => 'Devolución de retención de 5ta (ajuste final al cese): se declara como menor retención del mes, no como concepto remunerativo.',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('quinta_ajustes_cese');
        ConceptoRemuneracion::where('codigo', 'DEVOLUCION_RENTA_5TA')->delete();
    }
};
