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

        DB::table('colaboradores')->get(['id', 'contabilizar_faltas', 'contabilizar_horas_extra'])->each(fn ($c) =>
            DB::table('colaborador_condiciones_laborales')->where('colaborador_id', $c->id)->update([
                'contabilizar_faltas' => $c->contabilizar_faltas,
                'contabilizar_horas_extra' => $c->contabilizar_horas_extra,
            ])
        );
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
