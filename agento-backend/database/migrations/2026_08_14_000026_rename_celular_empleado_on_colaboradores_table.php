<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Homogeniza la terminología a "colaborador" en toda la tabla, no solo
     * en la UI: la columna se llamaba "celular_empleado".
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->renameColumn('celular_empleado', 'celular_colaborador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->renameColumn('celular_colaborador', 'celular_empleado');
        });
    }
};
