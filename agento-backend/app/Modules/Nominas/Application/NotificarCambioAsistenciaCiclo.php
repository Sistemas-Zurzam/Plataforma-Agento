<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Incremento 3 del endurecimiento Asistencia → Nómina. Punto único por el
 * que cualquier cambio de asistencia (aprobar/rechazar una hora extra,
 * reprocesar un día, reabrir un período, etc.) le avisa a Nómina que un
 * ciclo remunerativo que ya usó esos datos podría estar desactualizado.
 *
 * Contrato de invocación (responsabilidad del llamador, no de esta clase):
 * debe llamarse DESPUÉS de que la transacción que modificó la asistencia ya
 * haya confirmado (commit) — nunca desde dentro de ella. Si el cambio
 * original termina en rollback, el código simplemente nunca debe llegar a
 * esta llamada; esta clase no tiene forma de "deshacerse" a sí misma si se
 * invocara demasiado pronto.
 */
class NotificarCambioAsistenciaCiclo
{
    /**
     * Solo estos 3 estados tienen un cálculo previo que invalidar. 'abierto'
     * nunca se calculó (nada que invalidar); 'pagado' tiene su propia rama
     * (nunca se marca, se deriva a PlanillaComplementaria en su lugar).
     */
    private const ESTADOS_QUE_ADMITEN_RECALCULO = ['calculado', 'reabierto', 'cerrado'];

    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    public function notificar(
        int $empresaId,
        int $colaboradorId,
        string $fechaDesde,
        string $fechaHasta,
        string $motivo,
        ?string $referencia = null,
    ): void {
        // Clave determinística cuando no se pasa una referencia explícita:
        // la MISMA notificación (mismo colaborador, mismo rango, mismo
        // motivo) siempre produce la misma clave, así que repetirla es
        // idempotente sin necesitar que el llamador se acuerde de pasar
        // una referencia.
        $clave = $referencia ?? md5("{$colaboradorId}|{$fechaDesde}|{$fechaHasta}|{$motivo}");
        $finExclusivo = Carbon::parse($fechaHasta)->addDay()->toDateString();

        DB::transaction(function () use ($empresaId, $colaboradorId, $fechaDesde, $fechaHasta, $motivo, $clave, $finExclusivo) {
            $ciclos = CicloRemunerativo::query()
                ->where('empresa_id', $empresaId)
                ->where('fecha_inicio', '<', $finExclusivo)
                ->where('fecha_fin', '>=', $fechaDesde)
                ->lockForUpdate()
                ->get();

            foreach ($ciclos as $ciclo) {
                $this->procesarCiclo($ciclo, $colaboradorId, $fechaDesde, $fechaHasta, $motivo, $clave);
            }
        });
    }

    private function procesarCiclo(CicloRemunerativo $ciclo, int $colaboradorId, string $fechaDesde, string $fechaHasta, string $motivo, string $clave): void
    {
        if ($ciclo->estado === 'pagado') {
            $this->generarAjustePostPagoPendiente($ciclo, $colaboradorId, $fechaDesde, $fechaHasta, $motivo);

            return;
        }

        if (! in_array($ciclo->estado, self::ESTADOS_QUE_ADMITEN_RECALCULO, true)) {
            return; // 'abierto' nunca calculado -- nada que invalidar
        }

        if ($ciclo->recalculo_motivo && str_contains($ciclo->recalculo_motivo, "[{$clave}]")) {
            return; // misma notificación ya registrada -- idempotente
        }

        $antes = $ciclo->toArray();
        $linea = "[{$clave}] {$motivo} (colaborador #{$colaboradorId}, {$fechaDesde} a {$fechaHasta})";

        $ciclo->update([
            'requiere_recalculo' => true,
            'recalculo_motivo' => trim(($ciclo->recalculo_motivo ? $ciclo->recalculo_motivo."\n" : '').$linea),
            'recalculo_detectado_at' => $ciclo->recalculo_detectado_at ?? now(),
        ]);

        $this->auditoria->registrar($ciclo->empresa_id, null, 'ciclo_requiere_recalculo', $ciclo, $linea, $antes, $ciclo->fresh()->toArray());
    }

    /**
     * Se ancla al resultado diario más cercano al rango notificado (primero
     * intenta la última fecha, luego la primera) porque, a diferencia de las
     * incidencias semanales de descanso flexible, acá no hay un "domingo"
     * garantizado -- el rango lo define quien llama. Si ninguna de las dos
     * fechas tiene resultado diario todavía (caso raro), no hay dónde
     * anclar la incidencia y se omite en silencio en vez de fallar toda la
     * notificación por esto.
     */
    private function generarAjustePostPagoPendiente(CicloRemunerativo $ciclo, int $colaboradorId, string $fechaDesde, string $fechaHasta, string $motivo): void
    {
        $resultado = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereDate('fecha', $fechaHasta)
            ->first()
            ?? AsistenciaResultadoDiario::query()
                ->where('colaborador_id', $colaboradorId)
                ->whereDate('fecha', $fechaDesde)
                ->first();

        if (! $resultado) {
            return;
        }

        $existente = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $resultado->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_AJUSTE_POST_PAGO_PENDIENTE)
            ->first();
        if ($existente && $existente->estado !== AsistenciaIncidencia::ESTADO_PENDIENTE) {
            return; // ya revisada por una persona -- nunca se reabre sola
        }

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultado->id, 'tipo' => AsistenciaIncidencia::TIPO_AJUSTE_POST_PAGO_PENDIENTE],
            [
                'empresa_id' => $ciclo->empresa_id,
                'colaborador_id' => $colaboradorId,
                'fecha' => $resultado->fecha,
                'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
                'descripcion' => "El ciclo remunerativo '{$ciclo->nombre}' ({$ciclo->fecha_inicio->toDateString()} a {$ciclo->fecha_fin->toDateString()}) ya está pagado, pero cambió la asistencia: {$motivo}. Revisa si corresponde una planilla complementaria.",
            ]
        );
    }
}
