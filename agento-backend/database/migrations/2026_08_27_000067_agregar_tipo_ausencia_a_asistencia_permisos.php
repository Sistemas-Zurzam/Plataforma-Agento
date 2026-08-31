<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FK nullable a propósito: no se elimina ni se deja de usar
 * asistencia_permisos.tipo (sigue siendo el valor real que exige/produce
 * StoreAsistenciaPermisoRequest) — tipo_ausencia_id es la referencia
 * normalizada hacia el catálogo (para llegar al código SUNAT), backfileada
 * por coincidencia exacta de código, nunca una suposición.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->foreignId('tipo_ausencia_id')->nullable()->after('tipo')->constrained('tipos_ausencia')->restrictOnDelete();
        });

        DB::table('tipos_ausencia')->get(['id', 'codigo'])->each(function ($tipo) {
            DB::table('asistencia_permisos')
                ->whereNull('tipo_ausencia_id')->where('tipo', $tipo->codigo)
                ->update(['tipo_ausencia_id' => $tipo->id]);
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropForeign(['tipo_ausencia_id']);
        });
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropColumn('tipo_ausencia_id');
        });
    }
};
