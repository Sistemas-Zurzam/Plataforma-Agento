<?php

use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * La RMA debe formar parte del catálogo en todas las instalaciones.
     * Un seeder no garantiza que una base ya desplegada reciba nuevas
     * definiciones, por eso se registra también mediante migración.
     */
    public function up(): void
    {
        ParametroLaboralDefinicion::updateOrCreate(
            ['clave' => 'rma_afp'],
            [
                'grupo' => 'Aportes y Tasas',
                'nombre' => 'RMA (Remuneración Máxima Asegurable AFP)',
                'unidad' => 'S/',
                'orden' => 22,
            ],
        );
    }

    /**
     * No se elimina al revertir: podría existir desde antes de esta
     * migración y tener valores históricos asociados.
     */
    public function down(): void
    {
        // Intencionalmente vacío para preservar el historial laboral.
    }
};
