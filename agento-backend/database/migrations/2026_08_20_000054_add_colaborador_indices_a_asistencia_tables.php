<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los índices existentes de estas 2 tablas empiezan por empresa_id, pero
     * el motor de cálculo de planilla (CalcularBoletaColaborador) las
     * consulta por colaborador_id sin empresa_id — MySQL no puede aprovechar
     * un índice compuesto sin su columna líder. Invisible con pocos datos,
     * mide cada vez peor conforme crece el historial de asistencia.
     */
    public function up(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->index(['colaborador_id', 'fecha'], 'asistencia_resultados_colaborador_fecha_idx');
        });

        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->index(['colaborador_id', 'estado', 'con_goce'], 'asistencia_permisos_colaborador_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->dropIndex('asistencia_resultados_colaborador_fecha_idx');
        });

        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropIndex('asistencia_permisos_colaborador_estado_idx');
        });
    }
};
