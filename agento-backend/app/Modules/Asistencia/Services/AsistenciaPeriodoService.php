<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsistenciaPeriodoService
{
    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    public function listar(Empresa $empresa, int $perPage = 25): LengthAwarePaginator
    {
        return AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)
            ->orderByDesc('fecha_inicio')->paginate($perPage);
    }

    public function crear(Empresa $empresa, array $datos, int $usuarioId): AsistenciaPeriodo
    {
        $solapado = AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])->exists();
        if ($solapado) throw ValidationException::withMessages(['fecha_inicio' => ['El rango se superpone con otro período de asistencia.']]);

        $periodo = AsistenciaPeriodo::query()->create([...$datos, 'empresa_id' => $empresa->id, 'estado' => 'abierto']);
        $this->auditoria->registrar($empresa->id, $usuarioId, 'periodo_creado', $periodo, null, null, $periodo->toArray());
        return $periodo;
    }

    public function cambiarEstado(Empresa $empresa, AsistenciaPeriodo $periodo, string $accion, int $usuarioId, string $motivo): AsistenciaPeriodo
    {
        abort_unless($periodo->empresa_id === $empresa->id, 404);
        return DB::transaction(function () use ($empresa, $periodo, $accion, $usuarioId, $motivo) {
            $antes = $periodo->toArray();
            if ($accion === 'cerrar' && $periodo->estado === 'abierto') {
                $periodo->update(['estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $usuarioId]);
            } elseif ($accion === 'reabrir' && $periodo->estado === 'cerrado') {
                $periodo->update([
                    'estado' => 'abierto', 'reabierto_at' => now(), 'reabierto_por' => $usuarioId,
                    'version' => $periodo->version + 1, 'snapshot_nomina' => null,
                    'enviado_nomina_at' => null, 'enviado_nomina_por' => null,
                ]);
            } elseif ($accion === 'enviar_nomina' && $periodo->estado === 'cerrado') {
                $incidenciasPendientes = AsistenciaIncidencia::query()->where('empresa_id', $empresa->id)
                    ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])->where('estado', 'pendiente')->exists();
                $horasPendientes = AsistenciaHoraExtra::query()->where('empresa_id', $empresa->id)
                    ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])->where('estado', 'pendiente')->exists();
                $permisosPendientes = AsistenciaPermiso::query()->where('empresa_id', $empresa->id)
                    ->whereDate('fecha_inicio', '<=', $periodo->fecha_fin)->whereDate('fecha_fin', '>=', $periodo->fecha_inicio)
                    ->where('estado', 'pendiente')->exists();
                if ($incidenciasPendientes || $horasPendientes || $permisosPendientes) {
                    throw ValidationException::withMessages(['estado' => ['Resuelve las incidencias, horas extra y permisos pendientes antes de enviar el período a Nómina.']]);
                }
                $snapshot = AsistenciaResultadoDiario::query()->where('empresa_id', $empresa->id)
                    ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])
                    ->with(['colaborador:id,numero_documento,legajo', 'horasExtra'])->get()->map(fn ($resultado) => [
                        'colaborador_id' => $resultado->colaborador_id,
                        'documento' => $resultado->colaborador?->numero_documento,
                        'legajo' => $resultado->colaborador?->legajo,
                        'fecha' => $resultado->fecha->toDateString(),
                        'estado' => $resultado->estado,
                        'minutos_tardanza' => $resultado->minutos_tardanza,
                        'minutos_salida_anticipada' => $resultado->minutos_salida_anticipada,
                        'minutos_extra_25' => (int) $resultado->horasExtra->firstWhere('tasa', '25')?->minutos_aprobados,
                        'minutos_extra_35' => (int) $resultado->horasExtra->firstWhere('tasa', '35')?->minutos_aprobados,
                        'minutos_extra_100' => (int) $resultado->horasExtra->firstWhere('tasa', '100')?->minutos_aprobados,
                    ])->all();
                $periodo->update(['estado' => 'enviado_nomina', 'snapshot_nomina' => $snapshot, 'enviado_nomina_at' => now(), 'enviado_nomina_por' => $usuarioId]);
            } else {
                throw ValidationException::withMessages(['estado' => ['La transición solicitada no es válida para el período.']]);
            }
            $this->auditoria->registrar($empresa->id, $usuarioId, 'periodo_'.$accion, $periodo, $motivo, $antes, $periodo->fresh()->toArray());
            return $periodo->fresh();
        });
    }

    public function asegurarRangoEditable(int $empresaId, string $desde, string $hasta): void
    {
        $protegido = AsistenciaPeriodo::query()->where('empresa_id', $empresaId)
            ->whereIn('estado', ['cerrado', 'enviado_nomina'])
            ->whereDate('fecha_inicio', '<=', $hasta)->whereDate('fecha_fin', '>=', $desde)->exists();
        if ($protegido) throw ValidationException::withMessages(['fecha_desde' => ['El rango contiene un período cerrado o enviado a Nómina.']]);
    }
}
