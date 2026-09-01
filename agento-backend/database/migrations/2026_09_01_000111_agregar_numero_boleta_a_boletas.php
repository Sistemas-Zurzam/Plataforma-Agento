<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correlativo de boleta para auditoría — persistido (no solo calculado
     * al vuelo en el Resource) para poder buscar "qué boleta le corresponde
     * a qué colaborador" directamente por base de datos. Reglas acordadas
     * con el usuario:
     *   - Correlativo POR EMPRESA, POR PERÍODO (ciclo) — se reinicia en 1 en
     *     cada ciclo remunerativo de esa empresa.
     *   - Formato "{año de pago}-{correlativo con 6 dígitos}", ej. 2026-000001.
     *   - Estable entre versiones: si una boleta se recalcula, todas sus
     *     versiones (es_version_vigente true o false) conservan el MISMO
     *     número — representan el mismo documento corregido, no boletas
     *     distintas (ver BoletaService::generarNumeroBoleta()).
     */
    public function up(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->string('numero_boleta')->nullable()->after('id');
            $table->index(['ciclo_id', 'numero_boleta']);
        });

        $this->backfillRetroactivo();
    }

    /**
     * Asigna número retroactivo a las boletas ya existentes, siguiendo la
     * misma regla que aplicará BoletaService::generarNumeroBoleta() en
     * adelante. El orden dentro de cada ciclo se fija por la boleta MÁS
     * ANTIGUA de cada colaborador (MIN(id)) — no por la vigente — para que
     * el correlativo no dependa de cuántas veces se recalculó cada una.
     */
    private function backfillRetroactivo(): void
    {
        $ciclosIds = DB::table('boletas')->select('ciclo_id')->distinct()->pluck('ciclo_id');

        foreach ($ciclosIds as $cicloId) {
            $ciclo = DB::table('ciclos_remunerativos')->where('id', $cicloId)->first();
            if (! $ciclo) {
                continue; // ciclo borrado — no hay fecha de referencia confiable, se deja sin numerar
            }

            $anio = Carbon::parse($ciclo->fecha_pago ?? $ciclo->fecha_fin)->year;

            $colaboradorIdsEnOrden = DB::table('boletas')
                ->where('ciclo_id', $cicloId)
                ->select('colaborador_id', DB::raw('MIN(id) as primer_id'))
                ->groupBy('colaborador_id')
                ->orderBy('primer_id')
                ->pluck('colaborador_id');

            $correlativo = 1;
            foreach ($colaboradorIdsEnOrden as $colaboradorId) {
                DB::table('boletas')
                    ->where('ciclo_id', $cicloId)
                    ->where('colaborador_id', $colaboradorId)
                    ->update(['numero_boleta' => sprintf('%d-%06d', $anio, $correlativo)]);

                $correlativo++;
            }
        }
    }

    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropIndex(['ciclo_id', 'numero_boleta']);
            $table->dropColumn('numero_boleta');
        });
    }
};
