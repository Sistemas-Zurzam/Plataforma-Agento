<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue Empleado/Obrero DENTRO de tipo_trabajador="trabajador" —
     * Agento conserva su propio dominio (Rule::in en el Request sigue
     * siendo la fuente de verdad de los valores válidos); Tabla 8 de SUNAT
     * (21=Empleado, 20=Obrero) es responsabilidad exclusiva de Catálogos
     * SUNAT, nunca se guarda el código acá.
     *
     * Nullable en ambas tablas: solo aplica cuando tipo_trabajador es
     * "trabajador" (validado en el Request/Service, no en la BD).
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('categoria_trabajador', 20)->nullable()->after('tipo_trabajador');
        });

        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->string('categoria_trabajador', 20)->nullable()->after('tipo_contrato');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('categoria_trabajador');
        });

        Schema::table('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->dropColumn('categoria_trabajador');
        });
    }
};
