<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpresaSeguroSaludAplicableTest extends TestCase
{
    use RefreshDatabase;

    public function test_microempresa_remype_calcula_sis_aunque_el_valor_heredado_diga_essalud(): void
    {
        $empresa = Empresa::factory()->create([
            'regimen_laboral' => 'Micro Empresa',
            'inscrita_remype' => true,
            'seguro_salud' => 'essalud',
        ]);

        $this->assertSame('sis', $empresa->seguroSaludAplicable());
    }

    public function test_microempresa_sin_remype_conserva_el_seguro_configurado(): void
    {
        $empresa = Empresa::factory()->create([
            'regimen_laboral' => 'Micro Empresa',
            'inscrita_remype' => false,
            'seguro_salud' => 'essalud',
        ]);

        $this->assertSame('essalud', $empresa->seguroSaludAplicable());
    }

    public function test_pequena_empresa_conserva_el_seguro_configurado(): void
    {
        $empresa = Empresa::factory()->create([
            'regimen_laboral' => 'Pequeña Empresa',
            'inscrita_remype' => true,
            'seguro_salud' => 'essalud',
        ]);

        $this->assertSame('essalud', $empresa->seguroSaludAplicable());
    }
}
