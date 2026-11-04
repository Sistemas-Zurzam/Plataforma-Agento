<?php

use App\Modules\Asistencia\Application\MarcarDiasAjustadosExistentes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Día con ajuste manual (edición de jornada por RR.HH.): el motor no lo
 * vuelve a recalcular en procesos automáticos (importación, recálculo de
 * rango, carnet, descanso flexible). Solo "Restablecer" limpia la marca.
 *
 * Los datos existentes se completan solo para los días que todavía
 * conservan evidencia del ajuste (MarcarDiasAjustadosExistentes): no se
 * pide nada al usuario ni se congelan días cuya edición ya se perdió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->timestamp('ajustado_manualmente_at')->nullable()->after('estado_forzado');
            $table->foreignId('ajustado_por')->nullable()->after('ajustado_manualmente_at')->constrained('users')->nullOnDelete();
            $table->text('motivo_ajuste')->nullable()->after('ajustado_por');
        });

        // Solo días que todavía conservan evidencia del ajuste (ver la clase).
        app(MarcarDiasAjustadosExistentes::class)->ejecutar();
    }

    public function down(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ajustado_por');
            $table->dropColumn(['ajustado_manualmente_at', 'motivo_ajuste']);
        });
    }
};
