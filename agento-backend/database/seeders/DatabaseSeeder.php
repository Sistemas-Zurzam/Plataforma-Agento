<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(ParametroLaboralDefinicionSeeder::class);
        $this->call(AfpSeeder::class);

        // User::factory(10)->create();

        $empresa = Empresa::factory()->create([
            'nombre' => 'Empresa Demo',
            'ruc' => '20123456789',
            'direccion' => 'Av. Principal 123',
        ]);

        $user = User::factory()->create([
            'empresa_id' => $empresa->id,
            'name' => 'Test User',
            'username' => 'test.user',
            'email' => 'test@example.com',
        ]);

        $adminRoleId = Role::administrador()->id;

        $user->empresas()->updateExistingPivot($empresa->id, ['role_id' => $adminRoleId]);

        // Otras empresas que el mismo administrador también gestiona.
        Empresa::factory()
            ->count(2)
            ->create()
            ->each(fn (Empresa $otraEmpresa) => $user->empresas()->attach($otraEmpresa->id, [
                'role_id' => $adminRoleId,
            ]));

        // Compañeros de equipo en la empresa activa, con distintos roles,
        // para poder ver el listado y los contadores de "Usuarios y Roles".
        User::factory()
            ->count(3)
            ->create(['empresa_id' => $empresa->id])
            ->each(function (User $companero, int $index) use ($empresa) {
                $clave = ['talento_cultura', 'gerencia', 'jefe_area'][$index];
                $roleId = Role::where('clave', $clave)->value('id');
                $companero->empresas()->updateExistingPivot($empresa->id, ['role_id' => $roleId]);
            });
    }
}
