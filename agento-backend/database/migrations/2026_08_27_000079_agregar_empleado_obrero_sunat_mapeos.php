<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ahora que Colaborador distingue categoria_trabajador (empleado/
     * obrero) dentro de tipo_trabajador="trabajador" (ver migración
     * 000078), Catálogos SUNAT puede resolver Tabla 8 con certeza — se
     * agregan las 2 claves nuevas ya configuradas (21/20, confirmadas
     * contra el Anexo 2) y se desactiva la fila "trabajador" genérica
     * (superada por esta distinción, ya no representa una clasificación
     * seleccionable).
     */
    public function up(): void
    {
        $ahora = now();

        DB::table('sunat_mapeos')->insert([
            [
                'tipo' => 'tipo_trabajador', 'clave_interna' => 'empleado',
                'codigo_sunat' => '21', 'descripcion_sunat' => 'Empleado (Anexo 2, Tabla 8)',
                'activo' => true, 'bloqueado_por_modelo' => false, 'motivo_estado' => null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ],
            [
                'tipo' => 'tipo_trabajador', 'clave_interna' => 'obrero',
                'codigo_sunat' => '20', 'descripcion_sunat' => 'Obrero (Anexo 2, Tabla 8)',
                'activo' => true, 'bloqueado_por_modelo' => false, 'motivo_estado' => null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ],
        ]);

        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_trabajador')->where('clave_interna', 'trabajador')
            ->update([
                'activo' => false,
                'bloqueado_por_modelo' => false,
                'motivo_estado' => 'Superado por la distinción Empleado/Obrero (colaboradores.categoria_trabajador) — la resolución SUNAT ahora se hace por esa categoría, ver claves "empleado"/"obrero".',
                'updated_at' => $ahora,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sunat_mapeos')->where('tipo', 'tipo_trabajador')->whereIn('clave_interna', ['empleado', 'obrero'])->delete();

        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_trabajador')->where('clave_interna', 'trabajador')
            ->update(['activo' => true, 'motivo_estado' => null, 'updated_at' => now()]);
    }
};
