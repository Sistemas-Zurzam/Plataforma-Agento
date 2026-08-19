<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sistema_previsional ya existe como string libre ('onp' o la clave de
     * una AFP) — se conserva sin tocar. Estos campos son los que faltaban
     * para que el motor de nómina no tenga que adivinar la comisión ni
     * perder el CUSPP: afp_id referencia el catálogo real de AFP (en vez de
     * comparar strings), tipo_comision decide qué columna de comisiones_afp
     * usar, y cuspp se guarda como texto para no perder ceros/formato.
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->foreignId('afp_id')->nullable()->after('sistema_previsional')
                ->constrained('afps')->restrictOnDelete();
            $table->string('tipo_comision')->nullable()->after('afp_id'); // flujo | mixta
            $table->string('cuspp')->nullable()->after('tipo_comision');
            // Determina si corresponde asignación familiar — el MONTO nunca se
            // escribe a mano, siempre se resuelve desde el parámetro vigente
            // (10% RMV). Ver ParametrosVigentesResolver::paraRegimen().
            $table->boolean('tiene_hijos_asignacion_familiar')->default(false)->after('cuspp');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('afp_id');
            $table->dropColumn(['tipo_comision', 'cuspp', 'tiene_hijos_asignacion_familiar']);
        });
    }
};
