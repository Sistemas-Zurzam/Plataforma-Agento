<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot persistido de un cálculo de gratificación/CTS (Sección "CTS y
     * Gratificaciones" acordada con el usuario) — hasta ahora la pestaña
     * solo mostraba una suma en vivo de boleta_conceptos, sin versión ni
     * estado propio. "Calcular Gratificación/CTS" congela esa suma en un
     * snapshot versionado (mismo patrón que boletas: nunca se sobrescribe,
     * un recálculo apaga la versión anterior y crea una nueva).
     */
    public function up(): void
    {
        Schema::create('beneficios_sociales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo'); // gratificacion_julio | gratificacion_diciembre | cts_mayo | cts_noviembre
            $table->unsignedSmallInteger('anio');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('es_version_vigente')->default(true);
            $table->unsignedInteger('total_colaboradores');
            $table->decimal('total_bruto', 14, 2);
            $table->decimal('total_neto', 14, 2);
            $table->string('estado')->default('calculado'); // calculado | pagado
            $table->foreignId('calculado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculado_at');
            $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pagado_at')->nullable();
            $table->string('referencia_pago')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'tipo', 'anio', 'version'], 'beneficios_sociales_version_unique');
            $table->index(['empresa_id', 'tipo', 'anio', 'es_version_vigente'], 'beneficios_sociales_vigente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficios_sociales');
    }
};
