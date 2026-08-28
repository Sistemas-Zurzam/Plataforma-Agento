<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * V3 P3/T1 — trabajador de confianza debe ser histórico, igual que
     * régimen/tipo de contrato/AFP/comisión: una nómina de mayo debe poder
     * reconstruir si el colaborador era o no de confianza en mayo, sin
     * importar el valor actual en `colaboradores`. Mismo patrón que
     * 2026_08_27_000078_agregar_categoria_trabajador (columna nueva +
     * backfill del estado actual — nunca existió historial real antes de
     * esta migración, así que solo puede reconstruirse el estado vigente
     * al momento de migrar, mejor esfuerzo con vigencia_desde ya existente).
     * `colaboradores.es_trabajador_confianza` NO se elimina — sigue siendo
     * el valor vigente actual, igual que ya ocurre con afp_id/tipo_comision.
     */
    public function up(): void
    {
        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->boolean('es_trabajador_confianza')->nullable()->after('tipo_comision');
        });

        DB::statement(<<<'SQL'
            UPDATE colaborador_condiciones_laborales ccl
            INNER JOIN colaboradores c ON c.id = ccl.colaborador_id
            SET ccl.es_trabajador_confianza = c.es_trabajador_confianza
        SQL);
    }

    public function down(): void
    {
        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->dropColumn('es_trabajador_confianza');
        });
    }
};
