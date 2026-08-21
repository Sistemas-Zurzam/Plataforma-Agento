<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calcular una planilla completa recorre a TODOS los colaboradores
     * elegibles en un loop — con pocas decenas no se nota, pero con cientos
     * de colaboradores esa operación ya no debe bloquear el request HTTP.
     * Estas columnas permiten encolar el cálculo (CalcularPlanillaJob) y que
     * el frontend haga polling del resultado, en vez de esperar la
     * respuesta en vivo. `calculo_estado` es independiente del `estado`
     * propio del ciclo (abierto/calculado/cerrado/...) — uno describe el
     * ciclo de vida del período, el otro solo si HAY un cálculo en curso.
     */
    public function up(): void
    {
        Schema::table('ciclos_remunerativos', function (Blueprint $table) {
            $table->string('calculo_estado')->nullable()->after('estado'); // en_proceso | completado | error
            $table->timestamp('calculo_iniciado_at')->nullable()->after('calculo_estado');
            $table->timestamp('calculo_finalizado_at')->nullable()->after('calculo_iniciado_at');
            $table->json('calculo_resultado')->nullable()->after('calculo_finalizado_at');
        });
    }

    public function down(): void
    {
        Schema::table('ciclos_remunerativos', function (Blueprint $table) {
            $table->dropColumn(['calculo_estado', 'calculo_iniciado_at', 'calculo_finalizado_at', 'calculo_resultado']);
        });
    }
};
