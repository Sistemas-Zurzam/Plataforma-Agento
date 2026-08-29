<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CicloRemunerativoService
{
    /**
     * Lista los ciclos de TODAS las empresas autorizadas del usuario (no
     * solo la empresa activa) — el selector de "Planilla mensual" del
     * frontend los agrupa por empresa. $empresaIds ya viene resuelto y
     * autorizado por el controller (ver CicloRemunerativoController::
     * resolverEmpresaIds), este método nunca decide autorización.
     *
     * @param  array<int, int>  $empresaIds
     * @return LengthAwarePaginator<int, CicloRemunerativo>
     */
    public function listar(array $empresaIds, int $perPage = 15): LengthAwarePaginator
    {
        return CicloRemunerativo::whereIn('empresa_id', $empresaIds)
            ->with('empresa:id,nombre_comercial')
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

        $this->congelarDatosPago($ciclo);

        $ciclo->update(['estado' => 'cerrado']);

        return $ciclo;
    }

    /**
     * Congela los datos bancarios de cada boleta vigente EN EL MOMENTO del
     * cierre (preparación Telecrédito, Sección 22/23/25) — a partir de acá
     * la boleta ya no se puede recalcular, así que su instrucción de pago
     * tampoco debe cambiar si el colaborador actualiza su cuenta después.
     * `firstOrCreate` por boleta_id: idempotente, nunca sobrescribe un
     * snapshot que ya existía (ej. si cerrar() se reintenta).
     */
    private function congelarDatosPago(CicloRemunerativo $ciclo): void
    {
        $ahora = Carbon::now();

        $ciclo->boletas()
            ->where('es_version_vigente', true)
            ->with('colaborador')
            ->each(function (Boleta $boleta) use ($ahora) {
                $colaborador = $boleta->colaborador;
                if (! $colaborador) {
                    return;
                }

                BoletaDatosPago::firstOrCreate(
                    ['boleta_id' => $boleta->id],
                    [
                        'banco_id' => $colaborador->banco_id,
                        'tipo_cuenta_snapshot' => $colaborador->tipo_cuenta,
                        'moneda_snapshot' => $colaborador->moneda_cuenta,
                        'numero_cuenta_snapshot' => $colaborador->numero_cuenta,
                        'cci_snapshot' => $colaborador->cci,
                        'fecha_snapshot' => $ahora,
                    ],
                );
            });
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

        // Un ciclo puede seguir en estado 'cerrado' (nunca pasó por
        // marcarPagado()) aunque TODAS sus boletas vigentes ya estén
        // 'pagada' — cada boleta se paga individualmente, no en bloque. Sin
        // este chequeo, reabrir() dejaba recalcular un período cuyo dinero
        // ya salió, deshaciendo boletas ya pagadas.
        $tienePagadas = $ciclo->boletas()->where('es_version_vigente', true)
            ->where('estado', 'pagada')->exists();
        if ($tienePagadas) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede reabrir el período: ya tiene boletas pagadas.',
            ]);
        }

        // El snapshot bancario del cierre anterior queda obsoleto (Sección
        // 0 de la preparación Telecrédito): reabrir habilita recalcular, y
        // el colaborador pudo cambiar de cuenta mientras tanto. Se elimina
        // acá — nunca se conserva en silencio — para que el PRÓXIMO
        // cerrar() lo regenere con los datos vigentes en ESE momento. Ya
        // se verificó arriba que ninguna boleta está pagada, así que borrar
        // esto es seguro (no hay ninguna instrucción de pago ya ejecutada).
        BoletaDatosPago::whereIn('boleta_id', $ciclo->boletas()->select('id'))->delete();

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
     * Registra una comisión/bono/adelanto/descuento operativo que RR.HH.
     * quiere que el motor incluya al calcular la boleta de este colaborador
     * en este ciclo (Sección 46). También aplica a locadores (Recibos por
     * Honorarios) desde la incorporación de adelantos/descuentos operativos
     * para RH — ver CalcularReciboHonorarios::conceptosDelPeriodo() — pero
     * SOLO para conceptos de descuento (tipo=egreso): un locador no tiene
     * relación laboral, así que un ingreso remunerativo de planilla
     * dependiente (comisión, bonificación) no tiene cabida en su recibo por
     * honorarios (E20 .4ta, no Tabla 22). No se permite si el período ya
     * está cerrado/pagado — en ese estado ya no habrá un recálculo que lo
     * recoja, y dejaría un registro huérfano que sugiere falsamente que fue
     * considerado.
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
        // activo; CalcularBoletaColaborador/CalcularReciboHonorarios enrutan
        // la línea según ese tipo.
        $concepto = ConceptoRemuneracion::where('id', $datos['concepto_id'])->where('activo', true)->firstOrFail();

        $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios' || $colaborador->regimen_laboral === 'Locacion de Servicios';
        if ($esHonorarios && $concepto->tipo !== 'egreso') {
            throw ValidationException::withMessages([
                'concepto_id' => 'Un locador (Recibos por Honorarios) solo admite conceptos de descuento — los ingresos remunerativos son exclusivos de planilla dependiente.',
            ]);
        }

        return ColaboradorConceptoPeriodo::create([
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'colaborador_id' => $colaborador->id,
            'concepto_id' => $concepto->id,
            'concepto_definicion_id' => $datos['concepto_definicion_id'] ?? null,
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
