<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso para consultar la bitácora de auditoría. El administrador accede
 * siempre (EnsurePermission); este permiso permite delegarlo a otros roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        DB::table('permissions')->updateOrInsert(
            ['clave' => 'auditoria.ver'],
            ['nombre' => 'Ver bitácora de auditoría', 'grupo' => 'Auditoría', 'created_at' => $ahora, 'updated_at' => $ahora],
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('clave', 'auditoria.ver')->value('id');

        if ($id) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
