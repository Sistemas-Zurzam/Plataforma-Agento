<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los despliegues de producción ejecutan migraciones, no seeders. Este
     * concepto fue incorporado inicialmente al catálogo del seeder, por lo
     * que las instalaciones existentes no lo recibían y el firstOrFail del
     * cálculo terminaba presentándose erróneamente como un 404 de ruta.
     */
    public function up(): void
    {
        DB::table('conceptos_remuneracion')->updateOrInsert(
            ['codigo' => 'HONORARIO_FERIADO_TRABAJADO'],
            [
                'nombre' => 'Adicional por feriado trabajado (honorarios)',
                'tipo' => 'ingreso',
                'es_remunerativo_laboral' => false,
                'afecta_renta_5ta' => false,
                'afecta_afp' => false,
                'afecta_essalud' => false,
                'afecta_cts' => false,
                'afecta_gratificacion' => false,
                'afecta_vacaciones' => false,
                'codigo_plame' => null,
                'codigo_afpnet' => null,
                'alerta_recurrencia_meses' => null,
                'sunat_no_aplica' => true,
                'sunat_bloqueado_por_modelo' => false,
                'sunat_motivo_estado' => 'Pago adicional discrecional a un locador por trabajar un feriado histórico — no es un beneficio laboral; se declara como HONORARIO_BRUTO en la estructura E20 (.4ta), no corresponde a Tabla 22.',
                'activo' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // No se elimina: podría estar referenciado por complementarias ya
        // aprobadas o pagadas y forma parte del catálogo remunerativo.
    }
};
