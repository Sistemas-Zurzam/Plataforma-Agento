<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CicloRemunerativoService
{
    /**
     * @return LengthAwarePaginator<int, CicloRemunerativo>
     */
    public function listar(Empresa $empresa, int $perPage = 15): LengthAwarePaginator
    {
        return CicloRemunerativo::where('empresa_id', $empresa->id)
            ->withCount([
                'boletas' => fn ($query) => $query->where('es_version_vigente', true),
                'boletas as boletas_pendientes_aprobacion_count' => fn ($query) => $query
                    ->where('es_version_vigente', true)
                    ->whereNotIn('estado', ['aprobada', 'pagada']),
            ])
            ->orderByDesc('fecha_inicio')
            ->paginate($perPage);
    }

    /**
     * @throws ValidationException si el rango se solapa con un ciclo existente de la misma empresa
     */
    public function crear(Empresa $empresa, array $datos, int $usuarioId): CicloRemunerativo
    {
        $this->verificarNoSolapa($empresa, $datos['fecha_inicio'], $datos['fecha_fin']);

        return CicloRemunerativo::create([
            'empresa_id' => $empresa->id,
            'nombre' => $datos['nombre'],
            'periodicidad' => $datos['periodicidad'] ?? 'mensual',
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin' => $datos['fecha_fin'],
            'fecha_corte_asistencia' => $datos['fecha_corte_asistencia'],
            'fecha_pago' => $datos['fecha_pago'],
            'estado' => 'abierto',
            'creado_por' => $usuarioId,
        ]);
    }

    /**
     * Reglas de cierre (Sección 58): no se permite cerrar sin boletas
     * calculadas, ni con boletas vigentes que todavía no estén aprobadas.
     *
     * @throws ValidationException
     */
    public function cerrar(Empresa $empresa, CicloRemunerativo $ciclo): CicloRemunerativo
    {
        $this->verificarPertenencia($empresa, $ciclo);

        $totalVigentes = $ciclo->boletas()->where('es_version_vigente', true)->count();
        if ($totalVigentes === 0) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede cerrar un período sin boletas calculadas.',
            ]);
        }

        $sinAprobar = $ciclo->boletas()->where('es_version_vigente', true)
            ->whereNotIn('estado', ['aprobada', 'pagada'])->exists();
        if ($sinAprobar) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede cerrar el período: hay boletas sin aprobar.',
            ]);
        }

        $ciclo->update(['estado' => 'cerrado']);

        return $ciclo;
    }

    /**
     * Reapertura controlada (Sección 59): un ciclo cerrado no se recalcula
     * libremente — hay que reabrirlo explícitamente primero, dejando rastro
     * de quién y cuándo (queda en la auditoría del controller, no acá).
     */
    public function reabrir(Empresa $empresa, CicloRemunerativo $ciclo): CicloRemunerativo
    {
        $this->verificarPertenencia($empresa, $ciclo);

        if ($ciclo->estado === 'pagado') {
            throw ValidationException::withMessages([
                'estado' => 'No se puede reabrir un período ya pagado.',
            ]);
        }

        $ciclo->update(['estado' => 'reabierto']);

        return $ciclo;
    }

    /**
     * Cierra la transición Cerrado → Pagado (Sección 10 de la documentación
     * funcional): un período cerrado con todas sus boletas vigentes ya
     * pagadas queda formalizado como pagado. Antes de esto no existía
     * ningún camino de código que asignara 'pagado' a un ciclo — solo
     * reabrir() lo verificaba para bloquear la reapertura.
     *
     * @throws ValidationException
     */
    public function marcarPagado(Empresa $empresa, CicloRemunerativo $ciclo): CicloRemunerativo
    {
        $this->verificarPertenencia($empresa, $ciclo);

        if ($ciclo->estado !== 'cerrado') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede marcar como pagado un período cerrado.',
            ]);
        }

        $sinPagar = $ciclo->boletas()->where('es_version_vigente', true)
            ->where('estado', '!=', 'pagada')->exists();
        if ($sinPagar) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede marcar el período como pagado: hay boletas aprobadas pendientes de pago.',
            ]);
        }

        $ciclo->update(['estado' => 'pagado']);

        return $ciclo;
    }

    /**
     * Registra una comisión/bono/adelanto que RR.HH. quiere que el motor
     * incluya al calcular la boleta de este colaborador en este ciclo
     * (Sección 46). No se permite si el período ya está cerrado/pagado —
     * en ese estado ya no habrá un recálculo que lo recoja, y dejaría un
     * registro huérfano que sugiere falsamente que fue considerado.
     *
     * @throws ValidationException
     */
    public function registrarConcepto(Empresa $empresa, CicloRemunerativo $ciclo, Colaborador $colaborador, array $datos, int $usuarioId): ColaboradorConceptoPeriodo
    {
        $this->verificarPertenencia($empresa, $ciclo);

        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        if (in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Este período está cerrado — no se pueden registrar nuevos conceptos. Reábrelo primero si es necesario.',
            ]);
        }

        // El tipo (ingreso/egreso) siempre se toma del catálogo — nunca se
        // decide aquí — por eso basta con que el concepto exista y esté
        // activo; CalcularBoletaColaborador enruta la línea según ese tipo.
        $concepto = ConceptoRemuneracion::where('id', $datos['concepto_id'])->where('activo', true)->firstOrFail();

        return ColaboradorConceptoPeriodo::create([
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'colaborador_id' => $colaborador->id,
            'concepto_id' => $concepto->id,
            'monto' => $datos['monto'],
            'motivo' => $datos['motivo'] ?? null,
            'creado_por' => $usuarioId,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ColaboradorConceptoPeriodo>
     */
    public function listarConceptos(Empresa $empresa, CicloRemunerativo $ciclo, Colaborador $colaborador)
    {
        $this->verificarPertenencia($empresa, $ciclo);

        return ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)
            ->where('colaborador_id', $colaborador->id)
            ->with('concepto')
            ->orderByDesc('id')
            ->get();
    }

    private function verificarNoSolapa(Empresa $empresa, string $fechaInicio, string $fechaFin): void
    {
        $solapa = CicloRemunerativo::where('empresa_id', $empresa->id)
            ->where('fecha_inicio', '<=', $fechaFin)
            ->where('fecha_fin', '>=', $fechaInicio)
            ->exists();

        if ($solapa) {
            throw ValidationException::withMessages([
                'fecha_inicio' => 'Ya existe un ciclo remunerativo de esta empresa que se solapa con ese rango de fechas.',
            ]);
        }
    }

    private function verificarPertenencia(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este ciclo remunerativo no pertenece a la empresa activa.');
        }
    }
}
