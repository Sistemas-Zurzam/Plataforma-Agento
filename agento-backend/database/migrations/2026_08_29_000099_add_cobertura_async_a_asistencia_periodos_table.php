<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 4A — antes de cerrar un período, Agento debe garantizar que
     * TODAS las combinaciones colaborador×fecha aplicables ya pasaron por
     * ProcesarAsistenciaDiaria (ausencia de resultado ≠ incidencia
     * pendiente, era el hueco real). Con cientos de colaboradores esa
     * materialización no debe bloquear el request HTTP — mismo patrón ya
     * usado en ciclos_remunerativos.calculo_estado (ver migración
     * 2026_08_20_000050): AsegurarCoberturaAsistenciaPeriodoJob corre en
     * cola y el frontend hace polling de estas columnas.
     * `cobertura_estado` es independiente del `estado` propio del período
     * (abierto/cerrado/enviado_nomina) — uno describe el ciclo de vida del
     * período, el otro solo si HAY una materialización de cobertura en
     * curso.
     */
    public function up(): void
    {
        Schema::table('asistencia_periodos', function (Blueprint $table) {
            $table->string('cobertura_estado')->nullable()->after('estado'); // en_proceso | completado | error
            $table->timestamp('cobertura_iniciado_at')->nullable()->after('cobertura_estado');
            $table->timestamp('cobertura_finalizado_at')->nullable()->after('cobertura_iniciado_at');
            $table->json('cobertura_resultado')->nullable()->after('cobertura_finalizado_at');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_periodos', function (Blueprint $table) {
            $table->dropColumn(['cobertura_estado', 'cobertura_iniciado_at', 'cobertura_finalizado_at', 'cobertura_resultado']);
        });
    }
};
