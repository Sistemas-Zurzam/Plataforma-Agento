<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Las 5 empresas de prueba que SedeAreaHorarioSeeder y ColaboradorSeeder ya
 * dan por existentes (buscan por nombre_comercial "like"). firstOrCreate:
 * si alguna ya existe (creada desde la UI), no se duplica ni se pisa.
 */
class EmpresaSeeder extends Seeder
{
    private const EMPRESAS = [
        ['nombre_comercial' => 'Zazu', 'ruc' => '20600000011', 'regimen_laboral' => 'General'],
        ['nombre_comercial' => 'Agento', 'ruc' => '20600000012', 'regimen_laboral' => 'General'],
        ['nombre_comercial' => 'Texajo', 'ruc' => '20600000013', 'regimen_laboral' => 'Micro Empresa'],
        ['nombre_comercial' => 'Overshark', 'ruc' => '20600000014', 'regimen_laboral' => 'Pequeña Empresa'],
        ['nombre_comercial' => 'Bravos', 'ruc' => '20600000015', 'regimen_laboral' => 'General'],
    ];

    public function run(): void
    {
        $adminRoleId = Role::administrador()->id;

        $empresas = collect(self::EMPRESAS)->map(fn (array $datos) => Empresa::firstOrCreate(
            ['nombre_comercial' => $datos['nombre_comercial']],
            [
                'ruc' => $datos['ruc'],
                'direccion' => 'Av. Principal 123',
                'regimen_laboral' => $datos['regimen_laboral'],
                'activa' => true,
            ],
        ));

        $usuario = User::firstOrCreate(
            ['username' => 'test.user'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'empresa_id' => $empresas->first()->id,
            ],
        );

        foreach ($empresas as $empresa) {
            if (! $usuario->empresas()->where('empresas.id', $empresa->id)->exists()) {
                $usuario->empresas()->attach($empresa->id, ['role_id' => $adminRoleId]);
            }
        }

        $this->command?->info('EmpresaSeeder: 5 empresas listas (Zazu, Agento, Texajo, Overshark, Bravos).');
    }
}
