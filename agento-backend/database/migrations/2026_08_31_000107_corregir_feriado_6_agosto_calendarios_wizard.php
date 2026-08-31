<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Continuación de 2026_08_31_000106_corregir_feriado_6_agosto_calendarios_generados:
     * esa migración solo corrigió origen='horario_automatico' (filas
     * generadas por CalendarioMensualGenerator) — pero el calendario
     * INICIAL de un colaborador (Paso 2 del alta, ColaboradorService::crear())
     * se guarda con origen='wizard', y ese mismo hueco de FeriadosPeru
     * aplicaba igual: si el alta ocurrió antes de que FeriadosPeru incluyera
     * el 6 de agosto, calendarioPorDefecto() propuso ese día como laborable,
     * y RR.HH. lo guardó tal cual sin razón para haberlo cuestionado — no es
     * una decisión deliberada sobre ESE día puntual, es el mismo bug
     * heredado a través de otra ruta de escritura.
     *
     * Mismo filtro estricto que la migración anterior (año/mes/día,
     * tipo != 'feriado'), solo cambia el origen aceptado. Sigue sin tocar
     * 'manual', 'planificacion', 'incidencia', 'descanso_sustitutorio' ni
     * NULL — esos sí representan una edición explícita sobre ese día.
     */
    public function up(): void
    {
        DB::table('colaborador_calendario_dias')
            ->whereMonth('fecha', 8)
            ->whereDay('fecha', 6)
            ->whereYear('fecha', '>=', 2026)
            ->where('origen', 'wizard')
            ->where('tipo', '!=', 'feriado')
            ->update([
                'tipo' => 'feriado',
                'origen' => 'feriado_automatico',
                'updated_at' => now(),
            ]);
    }

    /**
     * No reversible — mismo motivo que la migración anterior: no se
     * conserva el tipo original, y revertir reintroduciría el mismo dato
     * incorrecto que esta migración corrige.
     */
    public function down(): void {}
};
