<?php

namespace Database\Seeders;

use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Catálogo de roles disponibles en el sistema. Compartido globalmente
     * (no por empresa): son la misma taxonomía de acceso para todas las
     * empresas del sistema, cada usuario tiene un rol de este catálogo
     * por cada empresa a la que pertenece (empresa_user.role_id).
     */
    public function run(): void
    {
        $roles = [
            ['clave' => 'administrador', 'nombre' => 'Administrador'],
            ['clave' => 'talento_cultura', 'nombre' => 'Talento y Cultura'],
            ['clave' => 'gerencia', 'nombre' => 'Gerencia'],
            ['clave' => 'jefe_area', 'nombre' => 'Jefe de Área'],
            ['clave' => 'solicitante', 'nombre' => 'Solicitante'],
            // Encargado de una empresa cliente en el Portal Cliente — no
            // hereda permisos administrativos (ver PermissionSeeder).
            ['clave' => 'cliente_empresa', 'nombre' => 'Cliente de Empresa'],
            ['clave' => 'vigilancia', 'nombre' => 'Vigilancia'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['clave' => $role['clave']], $role);
        }
    }
}
