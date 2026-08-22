<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametro_laboral_valores', function (Blueprint $table) {
            $table->foreignId('creado_por_id')->nullable()->after('valor')->constrained('users')->nullOnDelete();
            $table->string('motivo')->nullable()->after('creado_por_id');
        });

        Schema::table('comisiones_afp', function (Blueprint $table) {
            $table->foreignId('creado_por_id')->nullable()->after('sobre_saldo_anual_porcentaje')->constrained('users')->nullOnDelete();
            $table->string('motivo')->nullable()->after('creado_por_id');
        });
    }

    public function down(): void
    {
        Schema::table('parametro_laboral_valores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por_id');
            $table->dropColumn('motivo');
        });

        Schema::table('comisiones_afp', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por_id');
            $table->dropColumn('motivo');
        });
    }
};
