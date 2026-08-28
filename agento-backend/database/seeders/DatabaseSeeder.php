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
        $this->call(ConceptoRemuneracionSeeder::class);
        $this->call(TramoRentaSeeder::class);
        $this->call(TipoAusenciaSeeder::class);
        $this->call(SunatMapeoSeeder::class);
        $this->call(EmpresaSeeder::class);
        $this->call(ColaboradorSeeder::class);

        $empresa = Empresa::where('nombre_comercial', 'Agento')->first();
        $user = User::where('username', 'test.user')->first();

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
