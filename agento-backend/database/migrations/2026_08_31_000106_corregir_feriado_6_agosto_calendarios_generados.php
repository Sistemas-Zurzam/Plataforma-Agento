<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FeriadosPeru::paraAnio() no incluía el 6 de agosto (Batalla de
     * Junín) — cualquier calendario generado antes de agregarlo quedó con
     * ese día como laborable/descanso en vez de feriado. Esta migración
     * corrige esas filas ya persistidas; hacia adelante,
     * CalendarioMensualGenerator ya genera el día correctamente porque
     * consulta FeriadosPeru en el momento de generar.
     *
     * Filtro deliberadamente estricto: solo toca filas con
     * origen = 'horario_automatico' (generadas automáticamente por
     * CalendarioMensualGenerator sin que nadie las haya revisado). Nunca
     * toca 'manual', 'wizard', 'planificacion', 'incidencia',
     * 'descanso_sustitutorio' ni NULL (origen desconocido) — cualquiera de
     * esos representa una decisión humana o un caso que no es seguro
     * reinterpretar automáticamente, mismo criterio que usa
     * AjustarCalendarioPorCambioHorario.
     *
     * Acotado a partir de 2026 (YEAR(fecha) >= 2026): el feriado es nuevo,
     * no reescribe calendarios de años anteriores a su creación como ley,
     * aunque FeriadosPeru::paraAnio() ya lo devuelva para cualquier año
     * (eso solo afecta generación hacia adelante, nunca corrige historia).
     */
    public function up(): void
    {
        DB::table('colaborador_calendario_dias')
            ->whereRaw('MONTH(fecha) = 8 AND DAY(fecha) = 6 AND YEAR(fecha) >= 2026')
            ->where('origen', 'horario_automatico')
            ->where('tipo', '!=', 'feriado')
            ->update([
                'tipo' => 'feriado',
                'origen' => 'feriado_automatico',
                'updated_at' => now(),
            ]);
    }

    /**
     * No reversible — no se conserva el tipo original (laborable_presencial
     * vs. descanso) de cada fila corregida, y revertir significaría
     * reintroducir el mismo dato incorrecto que esta migración vino a
     * arreglar.
     */
    public function down(): void {}
};
