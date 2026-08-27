<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReprocesarAsistenciaRango
{
    public function __construct(
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<int, int>|null  $colaboradorIds
     * @return array{procesados: int, omitidos_sin_rol_rotativo: int}
     */
    public function ejecutar(
        Empresa $empresa,
        int $usuarioId,
        string $fechaDesde,
        string $fechaHasta,
        ?array $colaboradorIds,
        ?string $motivo,
    ): array {
        $this->periodos->asegurarRangoEditable($empresa->id, $fechaDesde, $fechaHasta);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->when($colaboradorIds, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        $inicio = Carbon::parse($fechaDesde);
        $fin = Carbon::parse($fechaHasta);
        $procesados = 0;
        $omitidosSinRolRotativo = 0;

        foreach ($colaboradores as $colaborador) {
            for ($fecha = $inicio->copy(); $fecha->lte($fin); $fecha->addDay()) {
                if ($fecha->lt($colaborador->fecha_ingreso->startOfDay())) {
                    continue;
                }
                try {
                    $this->procesador->procesar($colaborador, $fecha);
                    $procesados++;
                } catch (HttpExceptionInterface $e) {
                    // Horario rotativo sin rol declarado para esta fecha —
                    // se omite ese día puntual, no se aborta el resto del
                    // reprocesamiento (Sección: rotativos, cero inferencia).
                    $omitidosSinRolRotativo++;
                }
            }
        }

        $this->auditoria->registrar(
            $empresa->id, $usuarioId, 'asistencia_reprocesada', 'rango_asistencia',
            $motivo ?? 'Reprocesamiento manual', null,
            ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fechaHasta, 'colaborador_ids' => $colaboradorIds],
        );

        return ['procesados' => $procesados, 'omitidos_sin_rol_rotativo' => $omitidosSinRolRotativo];
    }
}
