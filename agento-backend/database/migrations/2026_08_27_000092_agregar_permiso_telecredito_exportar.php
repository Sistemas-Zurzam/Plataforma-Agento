<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso propio para la EXPORTACIÓN de Telecrédito BCP (Sección 35 del
 * encargo del exportador) — deliberadamente MÁS restrictivo que
 * `nominas.ver`: descargar este TXT puede terminar en movimiento real de
 * dinero una vez cargado al banco, a diferencia de solo ver ciclos/
 * boletas. La VALIDACIÓN (solo lectura, sin archivo) sigue usando
 * `nominas.ver`.
 *
 * Insertado directamente acá (mismo criterio que sunat_mapeos/
 * afpnet_mapeos): PermissionSeeder por sí solo no llega a una base de
 * datos ya migrada/sembrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('permissions')->where('clave', 'nominas.telecredito_exportar')->exists();
        if ($existe) {
            return;
        }

        $ahora = now();
        $permisoId = DB::table('permissions')->insertGetId([
            'clave' => 'nominas.telecredito_exportar',
            'nombre' => 'Exportar archivo Telecrédito BCP',
            'grupo' => 'Remuneraciones',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $administradorId = DB::table('roles')->where('clave', 'administrador')->value('id');
        if ($administradorId) {
            DB::table('role_permission')->insert([
                'role_id' => $administradorId,
                'permission_id' => $permisoId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')->where('clave', 'nominas.telecredito_exportar')->value('id');
        if ($permisoId) {
            DB::table('role_permission')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }
    }
};
