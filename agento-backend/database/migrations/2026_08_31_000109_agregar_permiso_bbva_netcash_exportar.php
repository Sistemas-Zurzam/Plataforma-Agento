<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso propio para la EXPORTACIÓN de BBVA Net Cash — deliberadamente
 * MÁS restrictivo que `nominas.ver` e independiente de
 * `nominas.telecredito_exportar` (mismo criterio: descargar este TXT
 * puede terminar en movimiento real de dinero una vez cargado al banco).
 * La VALIDACIÓN (solo lectura, sin archivo) sigue usando `nominas.ver`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('permissions')->where('clave', 'nominas.bbva_netcash_exportar')->exists();
        if ($existe) {
            return;
        }

        $ahora = now();
        $permisoId = DB::table('permissions')->insertGetId([
            'clave' => 'nominas.bbva_netcash_exportar',
            'nombre' => 'Exportar archivo BBVA Net Cash',
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
        $permisoId = DB::table('permissions')->where('clave', 'nominas.bbva_netcash_exportar')->value('id');
        if ($permisoId) {
            DB::table('role_permission')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }
    }
};
