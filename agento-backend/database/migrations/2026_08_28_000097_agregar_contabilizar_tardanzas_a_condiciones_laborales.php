<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * V3 P2/T1 — "contabilizar_tardanzas" pasa a tener efecto remunerativo
     * real (ver CalcularBoletaColaborador), así que debe historizarse igual
     * que es_trabajador_confianza: una nómina de marzo no debe reinterpretar
     * la tardanza con el valor de abril. Mismo patrón que
     * 2026_08_28_000095 (columna nueva + backfill del estado actual).
     * `colaboradores.contabilizar_tardanzas` NO se elimina — sigue siendo
     * el valor vigente actual, igual que ya ocurre con es_trabajador_confianza.
     *
     * contabilizar_horas_extra NO se historiza en esta migración — sigue sin
     * tener efecto en el cálculo (huérfano, reportado, fuera de alcance de
     * esta fase).
     */
    public function up(): void
    {
        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->boolean('contabilizar_tardanzas')->nullable()->after('es_trabajador_confianza');
        });

        DB::statement(<<<'SQL'
            UPDATE colaborador_condiciones_laborales ccl
            INNER JOIN colaboradores c ON c.id = ccl.colaborador_id
            SET ccl.contabilizar_tardanzas = c.contabilizar_tardanzas
        SQL);
    }

    public function down(): void
    {
        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->dropColumn('contabilizar_tardanzas');
        });
    }
};
