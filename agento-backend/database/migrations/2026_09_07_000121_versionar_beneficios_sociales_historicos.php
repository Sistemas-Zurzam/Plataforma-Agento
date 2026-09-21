<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endurecimiento post-Incremento 1 (revisión del propietario): el unique
 * original de `beneficios_sociales_historicos` (empresa+colaborador+vínculo+
 * tipo+año+periodo) no excluía `estado='anulado'` — anular un registro por
 * error de digitación dejaba esa combinación bloqueada para siempre, sin
 * forma de registrar la versión corregida. Se adopta el mismo patrón que
 * `LiquidacionCese`/`Boleta`/`BeneficioSocial`/`PlanillaComplementaria`:
 * `version` + `es_version_vigente`, nunca se sobrescribe una fila, una
 * corrección apaga la versión anterior y crea una nueva.
 *
 * La columna generada `periodo_vigente` (mismo mecanismo que
 * `fecha_corte_aprobada` en saldos_vacacionales_historicos) es lo que hace
 * el unique condicional: solo la versión VIGENTE ocupa la combinación
 * empresa+colaborador+vínculo+tipo+año+periodo — una anulada libera el
 * espacio para una corrección, sin perder el registro anulado (nunca se
 * borra, solo dice de vuelta `es_version_vigente=false`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // InnoDB (MySQL) no permite eliminar un índice si es el único que
        // respalda una foreign key (error 1553) — el unique compuesto
        // empieza por `empresa_id`, así que antes de soltarlo se crea un
        // índice de soporte propio para esa FK. SQLite no tiene esta
        // restricción, por eso no apareció al migrar contra el motor de
        // pruebas.
        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->index('empresa_id', 'beneficio_social_hist_empresa_idx');
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->dropUnique('beneficio_social_hist_vinculo_periodo_unique');
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('estado');
            $table->boolean('es_version_vigente')->default(true)->after('version');
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->string('periodo_vigente', 20)->nullable()->after('periodo')
                ->storedAs('CASE WHEN es_version_vigente = 1 THEN periodo ELSE NULL END');

            $table->unique(
                ['empresa_id', 'colaborador_id', 'fecha_ingreso_vinculo', 'tipo', 'anio', 'periodo_vigente'],
                'beneficio_social_hist_vinculo_periodo_vigente_unique'
            );
            $table->index(['colaborador_id', 'es_version_vigente'], 'beneficio_social_hist_colaborador_vigente_idx');
        });
    }

    public function down(): void
    {
        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->dropUnique('beneficio_social_hist_vinculo_periodo_vigente_unique');
            $table->dropIndex('beneficio_social_hist_colaborador_vigente_idx');
            $table->dropColumn('periodo_vigente');
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->dropColumn(['version', 'es_version_vigente']);
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->unique(
                ['empresa_id', 'colaborador_id', 'fecha_ingreso_vinculo', 'tipo', 'anio', 'periodo'],
                'beneficio_social_hist_vinculo_periodo_unique'
            );
        });

        Schema::table('beneficios_sociales_historicos', function (Blueprint $table) {
            $table->dropIndex('beneficio_social_hist_empresa_idx');
        });
    }
};
