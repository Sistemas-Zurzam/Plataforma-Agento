<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los horarios pasan a ser un catálogo global (compartido entre todas las
 * empresas de un mismo grupo) en vez de estar aislados por empresa.
 * empresa_id se conserva únicamente como dato informativo ("quién lo creó"),
 * ya no se usa para filtrar ni restringir acceso — por eso el FK cambia de
 * cascadeOnDelete a nullOnDelete: si la empresa creadora se elimina, el
 * horario debe sobrevivir (otras empresas pueden seguir usándolo), solo
 * pierde la referencia de quién lo creó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('horarios', fn (Blueprint $table) => $table->unsignedBigInteger('empresa_id')->nullable()->change());
        } else {
            DB::statement('ALTER TABLE horarios MODIFY empresa_id BIGINT UNSIGNED NULL');
        }

        Schema::table('horarios', function (Blueprint $table) {
            $table->foreign('empresa_id')->references('id')->on('empresas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('horarios', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('horarios', fn (Blueprint $table) => $table->unsignedBigInteger('empresa_id')->nullable(false)->change());
        } else {
            DB::statement('ALTER TABLE horarios MODIFY empresa_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('horarios', function (Blueprint $table) {
            $table->foreign('empresa_id')->references('id')->on('empresas')->cascadeOnDelete();
        });
    }
};
