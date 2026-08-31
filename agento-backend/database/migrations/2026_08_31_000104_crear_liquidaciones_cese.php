<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_cese', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->date('fecha_cese');
            $table->string('motivo_cese', 255);
            $table->decimal('remuneracion_snapshot', 12, 2);
            $table->string('regimen_laboral_snapshot', 80);
            $table->boolean('incluir_remuneracion')->default(true);
            $table->boolean('incluir_cts')->default(true);
            $table->boolean('incluir_gratificacion')->default(true);
            $table->boolean('incluir_vacaciones')->default(true);
            $table->decimal('total_ingresos', 12, 2)->default(0);
            $table->decimal('total_egresos', 12, 2)->default(0);
            $table->decimal('neto_pagar', 12, 2)->default(0);
            $table->string('estado', 30)->default('calculada');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('es_version_vigente')->default(true);
            $table->foreignId('calculado_por')->nullable()->constrained('users');
            $table->timestamp('calculado_at')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'fecha_cese']);
            $table->index(['colaborador_id', 'es_version_vigente']);
        });

        Schema::create('liquidacion_cese_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_cese_id')->constrained('liquidaciones_cese')->cascadeOnDelete();
            $table->string('codigo', 80);
            $table->string('nombre', 160);
            $table->string('tipo', 20)->default('ingreso');
            $table->decimal('monto', 12, 2);
            $table->decimal('base_utilizada', 12, 2)->nullable();
            $table->decimal('cantidad', 10, 4)->nullable();
            $table->decimal('tasa_aplicada', 10, 6)->nullable();
            $table->text('formula_texto')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidacion_cese_conceptos');
        Schema::dropIfExists('liquidaciones_cese');
    }
};
