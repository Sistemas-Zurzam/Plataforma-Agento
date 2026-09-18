<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mismo patrón que 2026_08_27_000092_agregar_permiso_telecredito_exportar —
 * PermissionSeeder solo alcanza entornos frescos/tests que corren
 * DatabaseSeeder; una BD ya sembrada necesita esta migración para recibir
 * el permiso nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('permissions')->where('clave', 'control_acceso.marcar')->exists();
        if ($existe) {
            return;
        }

        $ahora = now();
        $permisoId = DB::table('permissions')->insertGetId([
            'clave' => 'control_acceso.marcar',
            'nombre' => 'Marcar asistencia por carnet (Kiosco de Control de Acceso)',
            'grupo' => 'Asistencia',
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
        $permisoId = DB::table('permissions')->where('clave', 'control_acceso.marcar')->value('id');
        if ($permisoId) {
            DB::table('role_permission')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }
    }
};
