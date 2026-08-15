<?php

namespace Database\Seeders;

use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Catálogo fijo de permisos: cada uno corresponde a una acción real ya
     * implementada (ver el middleware EnsurePermission y las rutas en
     * routes/api.php). No es editable desde la UI — el admin solo asigna
     * estos permisos a roles, no crea permisos nuevos.
     */
    public function run(): void
    {
        $permisos = [
            ['clave' => 'empresas.crear', 'nombre' => 'Crear empresas', 'grupo' => 'Empresas'],
            ['clave' => 'empresas.editar', 'nombre' => 'Editar empresas', 'grupo' => 'Empresas'],
            ['clave' => 'sedes.crear', 'nombre' => 'Crear sedes', 'grupo' => 'Sedes'],
            ['clave' => 'sedes.editar', 'nombre' => 'Editar sedes', 'grupo' => 'Sedes'],
            ['clave' => 'areas.crear', 'nombre' => 'Crear áreas', 'grupo' => 'Áreas'],
            ['clave' => 'usuarios.ver', 'nombre' => 'Ver usuarios y roles', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.crear', 'nombre' => 'Crear usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.editar', 'nombre' => 'Editar usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.cambiar_rol', 'nombre' => 'Cambiar rol de usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.eliminar', 'nombre' => 'Eliminar usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'parametros_laborales.ver', 'nombre' => 'Ver parámetros laborales', 'grupo' => 'Parámetros Laborales'],
            ['clave' => 'parametros_laborales.editar', 'nombre' => 'Editar parámetros laborales', 'grupo' => 'Parámetros Laborales'],
            ['clave' => 'comisiones_afp.ver', 'nombre' => 'Ver comisiones AFP', 'grupo' => 'Comisiones AFP'],
            ['clave' => 'comisiones_afp.editar', 'nombre' => 'Editar comisiones AFP', 'grupo' => 'Comisiones AFP'],
            ['clave' => 'horarios.ver', 'nombre' => 'Ver horarios', 'grupo' => 'Horarios'],
            ['clave' => 'horarios.crear', 'nombre' => 'Crear horarios', 'grupo' => 'Horarios'],
            ['clave' => 'horarios.editar', 'nombre' => 'Editar horarios', 'grupo' => 'Horarios'],
            ['clave' => 'colaboradores.ver', 'nombre' => 'Ver colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'colaboradores.crear', 'nombre' => 'Crear colaboradores', 'grupo' => 'Colaboradores'],
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['clave' => $permiso['clave']], $permiso);
        }

        // El rol administrador siempre tiene acceso total. EnsurePermission
        // ya lo deja pasar sin mirar la matriz, pero sincronizamos igual
        // para que la UI de Permisos lo muestre consistentemente tildado.
        $administrador = Role::administrador();
        $administrador->permissions()->sync(Permission::pluck('id'));
    }
}
