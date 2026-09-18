<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mismo patrón que 2026_09_17_000115_agregar_permiso_control_acceso_marcar —
 * RoleSeeder solo alcanza entornos frescos; una BD ya sembrada necesita esta
 * migración para recibir el rol nuevo. El rol se crea SIN asignarle
 * permisos automáticamente (a diferencia del permiso nuevo, que sí se
 * asigna al administrador) — Vigilancia solo debe tener
 * `control_acceso.marcar`, y esa asignación es una decisión explícita de
 * cada empresa vía la UI de Usuarios y Roles, no algo que esta migración
 * deba forzar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('roles')->where('clave', 'vigilancia')->exists();
        if ($existe) {
            return;
        }

        DB::table('roles')->insert([
            'clave' => 'vigilancia',
            'nombre' => 'Vigilancia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('clave', 'vigilancia')->delete();
    }
};
