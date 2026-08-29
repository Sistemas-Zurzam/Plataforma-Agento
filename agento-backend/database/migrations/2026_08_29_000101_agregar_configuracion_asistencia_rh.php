<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('contabilizar_faltas')->default(true)->after('contabilizar_tardanzas');
        });

        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->boolean('contabilizar_faltas')->default(true)->after('contabilizar_tardanzas');
            $table->boolean('contabilizar_horas_extra')->default(true)->after('contabilizar_faltas');
        });

        DB::statement(<<<'SQL'
            UPDATE colaborador_condiciones_laborales condiciones
            INNER JOIN colaboradores ON colaboradores.id = condiciones.colaborador_id
            SET condiciones.contabilizar_faltas = colaboradores.contabilizar_faltas,
                condiciones.contabilizar_horas_extra = colaboradores.contabilizar_horas_extra
        SQL);
    }

    public function down(): void
    {
        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->dropColumn(['contabilizar_faltas', 'contabilizar_horas_extra']);
        });

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('contabilizar_faltas');
        });
    }
};
