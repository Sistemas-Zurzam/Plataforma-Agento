<?php

namespace App\Modules\Nominas\Jobs;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\BoletaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Calcular una planilla recorre a TODOS los colaboradores elegibles del
 * ciclo en un loop — con cientos de colaboradores ya no debe bloquear el
 * request HTTP que lo dispara. El controller solo encola este job y
 * responde de inmediato; el frontend hace polling de
 * `ciclos_remunerativos.calculo_estado` hasta que termine.
 */
class CalcularPlanillaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $empresaId,
        private readonly int $cicloId,
        private readonly int $usuarioId,
        private readonly ?string $motivoRecalculo,
    ) {}

    public function handle(BoletaService $boletas): void
    {
        $empresa = Empresa::findOrFail($this->empresaId);
        $ciclo = CicloRemunerativo::findOrFail($this->cicloId);

        try {
            $resultado = $boletas->calcularPlanilla($empresa, $ciclo, $this->usuarioId, $this->motivoRecalculo);

            $ciclo->update([
                'calculo_estado' => 'completado',
                'calculo_finalizado_at' => now(),
                'calculo_resultado' => $resultado,
            ]);
        } catch (Throwable $e) {
            $ciclo->update([
                'calculo_estado' => 'error',
                'calculo_finalizado_at' => now(),
                'calculo_resultado' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        }
    }
}
