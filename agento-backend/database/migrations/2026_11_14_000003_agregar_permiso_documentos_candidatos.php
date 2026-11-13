<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso para ver/descargar los documentos de candidatos (DNI, CV, CUL...)
 * desde el banco de talentos. Asignación inicial: talento_cultura (RYS); el
 * administrador accede siempre. Solo agrega, nunca quita permisos.
 */
return new class extends Migration
{
    private const CLAVE = 'seleccion.documentos.ver';

    public function up(): void
    {
        $ahora = now();

        DB::table('permissions')->updateOrInsert(
            ['clave' => self::CLAVE],
            [
                'nombre' => 'Ver y descargar documentos de candidatos',
                'grupo' => 'Selección y Reclutamiento',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        );

        $permisoId = DB::table('permissions')->where('clave', self::CLAVE)->value('id');
        $rolId = DB::table('roles')->where('clave', 'talento_cultura')->value('id');

        if ($rolId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $rolId,
                'permission_id' => $permisoId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('clave', self::CLAVE)->value('id');

        if ($id) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
