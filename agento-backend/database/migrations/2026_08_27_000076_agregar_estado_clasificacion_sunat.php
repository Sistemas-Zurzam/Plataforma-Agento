<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogos SUNAT necesita distinguir 4 estados (configurado/requiere_
     * configuración/bloqueado_por_modelo/no_aplica), no solo "tiene código
     * o no". `activo` en sunat_mapeos ya representaba "no aplica" (tabla
     * inventada solo para esta pantalla, sin otro consumidor) y se
     * mantiene así. En tipos_ausencia y conceptos_remuneracion, en cambio,
     * `activo` es un flag REAL usado por Asistencia/Nóminas (si un tipo de
     * ausencia o concepto está disponible para uso) — NO puede
     * reutilizarse para "no aplica a SUNAT" sin romper esa funcionalidad,
     * así que ahí se agregan columnas nuevas con prefijo `sunat_` para no
     * confundirlas con las columnas reales del dominio.
     *
     * `motivo_estado`/`sunat_motivo_estado` es la explicación INTERNA de
     * Agento (por qué está bloqueado/no aplica/requiere elección) —
     * separada de `descripcion_sunat` (la descripción OFICIAL de SUNAT),
     * que hasta ahora mezclaba ambas cosas.
     */
    public function up(): void
    {
        Schema::table('sunat_mapeos', function (Blueprint $table) {
            $table->boolean('bloqueado_por_modelo')->default(false)->after('activo');
            $table->text('motivo_estado')->nullable()->after('descripcion_sunat');
        });

        Schema::table('tipos_ausencia', function (Blueprint $table) {
            $table->boolean('sunat_no_aplica')->default(false)->after('descripcion_sunat');
            $table->boolean('sunat_bloqueado_por_modelo')->default(false)->after('sunat_no_aplica');
            $table->text('sunat_motivo_estado')->nullable()->after('sunat_bloqueado_por_modelo');
        });

        Schema::table('conceptos_remuneracion', function (Blueprint $table) {
            $table->boolean('sunat_no_aplica')->default(false)->after('codigo_plame');
            $table->boolean('sunat_bloqueado_por_modelo')->default(false)->after('sunat_no_aplica');
            $table->text('sunat_motivo_estado')->nullable()->after('sunat_bloqueado_por_modelo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sunat_mapeos', function (Blueprint $table) {
            $table->dropColumn(['bloqueado_por_modelo', 'motivo_estado']);
        });

        Schema::table('tipos_ausencia', function (Blueprint $table) {
            $table->dropColumn(['sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado']);
        });

        Schema::table('conceptos_remuneracion', function (Blueprint $table) {
            $table->dropColumn(['sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado']);
        });
    }
};
