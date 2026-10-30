<?php

namespace App\Modules\Nominas\Application;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\PropuestaConcepto;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Circuito de propuestas de bono/comisión: gerencia propone (portal) →
 * RR. HH. valida o rechaza (Remuneraciones). Validar NO calcula nada: reusa
 * CicloRemunerativoService::registrarConcepto(), el mismo caso de uso que
 * RR. HH. ya usa para conceptos manuales del ciclo, y guarda el vínculo.
 */
class PropuestaConceptoService
{
    /** Ciclos que todavía aceptan conceptos (mismo criterio que registrarConcepto). */
    public const ESTADOS_CICLO_ABIERTO = ['abierto', 'calculado', 'reabierto'];

    public function __construct(private readonly CicloRemunerativoService $ciclos) {}

    /**
     * @return Collection<int, CicloRemunerativo>
     */
    public function ciclosAbiertos(Empresa $empresa)
    {
        return CicloRemunerativo::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('estado', self::ESTADOS_CICLO_ABIERTO)
            ->orderByDesc('fecha_inicio')
            ->get(['id', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado']);
    }

    /**
     * @param  array{ciclo_id: int, tipo: string, monto: string, motivo: string, clave_idempotencia: string}  $datos
     * @return array{propuesta: PropuestaConcepto, creada: bool}
     */
    public function proponer(Empresa $empresa, Colaborador $colaborador, array $datos, User $usuario): array
    {
        $existente = $this->porClave($empresa, $usuario, $datos['clave_idempotencia']);
        if ($existente) {
            return ['propuesta' => $existente, 'creada' => false];
        }

        $ciclo = CicloRemunerativo::query()->where('empresa_id', $empresa->id)->whereKey($datos['ciclo_id'])->first();
        if (! $ciclo) {
            throw ValidationException::withMessages(['ciclo_id' => ['El ciclo indicado no es válido.']]);
        }
        if (! in_array($ciclo->estado, self::ESTADOS_CICLO_ABIERTO, true)) {
            throw ValidationException::withMessages(['ciclo_id' => ['El ciclo está cerrado o pagado; ya no admite propuestas.']]);
        }

        try {
            $propuesta = PropuestaConcepto::create([
                'empresa_id' => $empresa->id,
                'ciclo_id' => $ciclo->id,
                'colaborador_id' => $colaborador->id,
                'tipo' => $datos['tipo'],
                'monto' => $datos['monto'],
                'motivo' => $datos['motivo'],
                'origen' => 'portal',
                'estado' => PropuestaConcepto::ESTADO_PROPUESTO,
                'propuesto_por' => $usuario->id,
                'clave_idempotencia' => $datos['clave_idempotencia'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Reintento concurrente con la misma clave: gana el primero.
            return ['propuesta' => $this->porClave($empresa, $usuario, $datos['clave_idempotencia']), 'creada' => false];
        }

        return ['propuesta' => $propuesta, 'creada' => true];
    }

    private function porClave(Empresa $empresa, User $usuario, string $clave): ?PropuestaConcepto
    {
        return PropuestaConcepto::query()
            ->where('empresa_id', $empresa->id)
            ->where('propuesto_por', $usuario->id)
            ->where('clave_idempotencia', $clave)
            ->first();
    }

    /**
     * @param  Builder<PropuestaConcepto>  $query
     * @param  array<string, mixed>  $filtros
     */
    public function listar(Builder $query, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $query
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['ciclo_id'] ?? null, fn ($q, $cicloId) => $q->where('ciclo_id', $cicloId))
            ->with(['colaborador.area', 'ciclo:id,nombre,estado,fecha_inicio,fecha_fin', 'proponente:id,name', 'resolutor:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Validación de RR. HH.: registra el concepto del ciclo con el caso de
     * uso existente (mismas reglas: ciclo editable, concepto activo,
     * locadores solo egresos) y guarda el vínculo. Bloqueo + estado evitan
     * validar dos veces.
     *
     * @param  array{concepto_id: int, concepto_definicion_id?: ?int, observacion?: ?string}  $datos
     */
    public function validar(Empresa $empresa, PropuestaConcepto $propuesta, array $datos, User $usuario): PropuestaConcepto
    {
        return DB::transaction(function () use ($empresa, $propuesta, $datos, $usuario) {
            $propuesta = $this->bloquearPendiente($empresa, $propuesta);
            $ciclo = CicloRemunerativo::query()->whereKey($propuesta->ciclo_id)->firstOrFail();
            $colaborador = Colaborador::query()->whereKey($propuesta->colaborador_id)->firstOrFail();

            $concepto = $this->ciclos->registrarConcepto($empresa, $ciclo, $colaborador, [
                'concepto_id' => $datos['concepto_id'],
                'concepto_definicion_id' => $datos['concepto_definicion_id'] ?? null,
                'monto' => (string) $propuesta->monto,
                'motivo' => mb_substr("Propuesta de gerencia #{$propuesta->id}: {$propuesta->motivo}", 0, 255),
            ], $usuario->id);

            $propuesta->update([
                'estado' => PropuestaConcepto::ESTADO_VALIDADO,
                'observacion_rrhh' => $datos['observacion'] ?? null,
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
                'colaborador_concepto_periodo_id' => $concepto->id,
            ]);

            return $propuesta->fresh(['colaborador.area', 'ciclo', 'proponente:id,name', 'resolutor:id,name']);
        });
    }

    public function rechazar(Empresa $empresa, PropuestaConcepto $propuesta, string $observacion, User $usuario): PropuestaConcepto
    {
        return DB::transaction(function () use ($empresa, $propuesta, $observacion, $usuario) {
            $propuesta = $this->bloquearPendiente($empresa, $propuesta);
            $propuesta->update([
                'estado' => PropuestaConcepto::ESTADO_RECHAZADO,
                'observacion_rrhh' => $observacion,
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);

            return $propuesta->fresh(['colaborador.area', 'ciclo', 'proponente:id,name', 'resolutor:id,name']);
        });
    }

    private function bloquearPendiente(Empresa $empresa, PropuestaConcepto $propuesta): PropuestaConcepto
    {
        $bloqueada = PropuestaConcepto::query()->where('empresa_id', $empresa->id)->whereKey($propuesta->id)->lockForUpdate()->first();
        abort_unless($bloqueada, 404);
        if ($bloqueada->estado !== PropuestaConcepto::ESTADO_PROPUESTO) {
            throw new ConflictHttpException('La propuesta ya fue resuelta. Recarga la bandeja.');
        }

        return $bloqueada;
    }
}
