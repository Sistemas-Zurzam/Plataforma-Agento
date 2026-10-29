<?php

namespace App\Modules\Reclutamiento\Console;

use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use Illuminate\Console\Command;

class CerrarIntentosVencidosCommand extends Command
{
    protected $signature = 'reclutamiento:cerrar-intentos-vencidos';

    protected $description = 'Cierra intentos de pruebas cuyo tiempo venció y asignaciones fuera de plazo';

    public function handle(AplicacionPruebaService $pruebas): int
    {
        $cerrados = $pruebas->cerrarVencidos();
        $this->info("Intentos cerrados por tiempo: {$cerrados}");

        return self::SUCCESS;
    }
}
