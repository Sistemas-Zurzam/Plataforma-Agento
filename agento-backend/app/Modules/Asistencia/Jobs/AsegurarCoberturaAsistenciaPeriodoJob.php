<?php

namespace App\Modules\Asistencia\Jobs;

use App\Modules\Asistencia\Application\AsegurarCoberturaAsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Materializar cobertura recorre a TODOS los colaboradores×fechas
 * faltantes del período en un loop — con cientos de colaboradores ya no
 * debe bloquear el request HTTP que lo dispara. El controller solo encola
 * este job y responde de inmediato; el frontend hace polling de
 * `asistencia_periodos.cobertura_estado` hasta que termine — mismo patrón
 * que CalcularPlanillaJob (Nóminas) para `ciclos_remunerativos.calculo_estado`.
 */
class AsegurarCoberturaAsistenciaPeriodoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $empresaId,
        private readonly int $periodoId,
    ) {}

    public function handle(AsegurarCoberturaAsistenciaPeriodo $cobertura, AsistenciaAuditoriaService $auditoria): void
    {
        $empresa = Empresa::findOrFail($this->empresaId);
        $periodo = AsistenciaPeriodo::findOrFail($this->periodoId);

        try {
            $resultado = $cobertura->ejecutar($empresa, $periodo);
            $estadoFinal = $resultado['errores'] === [] ? 'completado' : 'error';

            $periodo->update([
                'cobertura_estado' => $estadoFinal,
                'cobertura_finalizado_at' => now(),
                'cobertura_resultado' => $resultado,
            ]);

            $auditoria->registrar($empresa->id, null, 'cobertura_'.$estadoFinal, $periodo, null, null, $resultado);
        } catch (Throwable $e) {
            $periodo->update([
                'cobertura_estado' => 'error',
                'cobertura_finalizado_at' => now(),
                'cobertura_resultado' => ['error' => $e->getMessage()],
            ]);

            $auditoria->registrar($empresa->id, null, 'cobertura_error', $periodo, $e->getMessage(), null, null);

            throw $e;
        }
    }
}
