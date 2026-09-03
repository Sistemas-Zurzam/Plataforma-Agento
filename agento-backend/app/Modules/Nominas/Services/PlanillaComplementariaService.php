<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtExporter;
use App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export\TelecreditoBcpTxtExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanillaComplementariaService
{
    public function __construct(
        private readonly CalcularBoletaColaborador $calculador,
        private readonly CalcularReciboHonorarios $calculadorHonorarios,
    ) {}

    public function listar(Empresa $empresa, CicloRemunerativo $ciclo): Collection
    {
        $this->verificar($empresa, $ciclo);

        return PlanillaComplementaria::where('ciclo_id', $ciclo->id)
            ->with(['detalles.colaborador:id,nombres,apellidos,numero_documento', 'detalles.boletaOriginal.datosPago.banco'])
            ->latest()->get();
    }

    /** @param array<int, int> $boletaIds */
    public function crear(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        $this->verificar($empresa, $ciclo);
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'La planilla complementaria solo se genera sobre un ciclo pagado.']);
        }

        $originales = Boleta::where('ciclo_id', $ciclo->id)
            ->whereIn('id', $boletaIds)
            ->where('estado', 'pagada')
            ->where('es_version_vigente', true)
            ->with('colaborador')
            ->get();

        if ($originales->count() !== count(array_unique($boletaIds))) {
            throw ValidationException::withMessages(['colaboradores' => 'Todos los colaboradores deben tener una boleta vigente pagada en el ciclo.']);
        }

        $pendienteExistente = PlanillaComplementariaDetalle::whereIn('colaborador_id', $originales->pluck('colaborador_id'))
            ->whereHas('complementaria', fn ($q) => $q->where('ciclo_id', $ciclo->id)->whereIn('estado', ['calculada', 'aprobada']))
            ->exists();
        if ($pendienteExistente) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Uno de los colaboradores ya tiene una complementaria pendiente. Apruébala y págala antes de generar otra para evitar duplicar el abono.',
            ]);
        }

        return DB::transaction(function () use ($ciclo, $originales, $motivo, $usuarioId) {
            $complementaria = PlanillaComplementaria::create([
                'ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'nombre' => 'Complementaria '.$ciclo->nombre.' '.now()->format('Ymd-His'),
                'motivo' => $motivo,
                'estado' => 'calculada',
                'creado_por' => $usuarioId,
            ]);

            foreach ($originales as $original) {
                $colaborador = $original->colaborador;
                $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios'
                    || $colaborador->regimen_laboral === 'Locacion de Servicios';
                $motor = $esHonorarios ? $this->calculadorHonorarios : $this->calculador;
                $nuevo = $motor->calcular(
                    $colaborador,
                    $ciclo->fecha_inicio->toDateString(),
                    $ciclo->fecha_fin->toDateString(),
                    $ciclo->fecha_corte_asistencia->toDateString(),
                    $ciclo->id,
                    $ciclo->fecha_pago->toDateString(),
                );

                // Si ya hubo una complementaria pagada, la nueva diferencia
                // parte del último cálculo efectivamente cubierto, no vuelve
                // a compararse contra la boleta original (antiduplicidad).
                $ultimaPagada = PlanillaComplementariaDetalle::where('boleta_original_id', $original->id)
                    ->whereHas('complementaria', fn ($q) => $q->where('estado', 'pagada'))
                    ->latest('id')->first();
                $base = $ultimaPagada?->calculo_snapshot ?? [
                    'neto_a_pagar' => $original->neto_a_pagar,
                    'total_ingresos' => $original->total_ingresos,
                    'total_egresos' => $original->total_egresos,
                    'total_aportaciones' => $original->total_aportaciones,
                ];

                PlanillaComplementariaDetalle::create([
                    'planilla_complementaria_id' => $complementaria->id,
                    'boleta_original_id' => $original->id,
                    'colaborador_id' => $colaborador->id,
                    'banco_id' => $colaborador->banco_id,
                    'tipo_cuenta_snapshot' => $colaborador->tipo_cuenta,
                    'moneda_snapshot' => $colaborador->moneda_cuenta,
                    'numero_cuenta_snapshot' => $colaborador->numero_cuenta,
                    'cci_snapshot' => $colaborador->cci,
                    'neto_original' => $base['neto_a_pagar'],
                    'neto_recalculado' => $nuevo['neto_a_pagar'],
                    'diferencia_ingresos' => bcsub((string) $nuevo['total_ingresos'], (string) $base['total_ingresos'], 2),
                    'diferencia_egresos' => bcsub((string) $nuevo['total_egresos'], (string) $base['total_egresos'], 2),
                    'diferencia_aportaciones' => bcsub((string) $nuevo['total_aportaciones'], (string) $base['total_aportaciones'], 2),
                    'diferencia_neta' => bcsub((string) $nuevo['neto_a_pagar'], (string) $base['neto_a_pagar'], 2),
                    'calculo_snapshot' => $nuevo,
                ]);
            }

            if (! $complementaria->detalles()->where('diferencia_neta', '!=', 0)->exists()) {
                throw ValidationException::withMessages(['diferencias' => 'El recálculo no produjo ninguna diferencia respecto de lo ya pagado.']);
            }

            return $this->cargar($complementaria);
        });
    }

    /**
     * Agrega un concepto manual (bono, comisión, descuento) a un colaborador
     * de una complementaria — únicamente mientras esté "calculada" (antes de
     * aprobarla): una vez aprobada, el monto ya quedó confirmado para pago/
     * exportación bancaria y para el PLAME (PlameCicloDatosLoader lee este
     * mismo calculo_snapshot), así que ya no debe seguir cambiando.
     *
     * No suma una línea "delta" aparte de calculo_snapshot: la agrega
     * directamente al bloque ingresos/egresos (misma forma que produce
     * CalcularBoletaColaborador::calcular()) y recalcula los totales del
     * snapshot para que quede internamente consistente — tanto para esta
     * exportación como para una futura complementaria que encadene sobre
     * "última pagada" (ver crear()).
     */
    public function agregarConcepto(Empresa $empresa, PlanillaComplementariaDetalle $detalle, int $conceptoId, ?int $conceptoDefinicionId, float $monto, ?string $motivo, int $usuarioId): PlanillaComplementaria
    {
        $item = $detalle->complementaria;
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden agregar conceptos mientras la complementaria esté calculada, antes de aprobarla.']);
        }

        $concepto = ConceptoRemuneracion::where('id', $conceptoId)->where('activo', true)->firstOrFail();

        if (! in_array($concepto->tipo, ['ingreso', 'egreso'], true)) {
            throw ValidationException::withMessages(['concepto_id' => 'Solo se pueden agregar manualmente conceptos de tipo ingreso o egreso.']);
        }

        $colaborador = $detalle->colaborador;
        $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios' || $colaborador->regimen_laboral === 'Locacion de Servicios';
        if ($esHonorarios && $concepto->tipo !== 'egreso') {
            throw ValidationException::withMessages([
                'concepto_id' => 'Un locador (Recibos por Honorarios) solo admite conceptos de descuento — los ingresos remunerativos son exclusivos de planilla dependiente.',
            ]);
        }

        $montoRedondeado = round($monto, 2);
        $bloque = $concepto->tipo === 'ingreso' ? 'ingresos' : 'egresos';

        DB::transaction(function () use ($detalle, $concepto, $conceptoDefinicionId, $montoRedondeado, $bloque, $motivo, $usuarioId) {
            $snapshot = $detalle->calculo_snapshot;
            $snapshot[$bloque][] = [
                // Identificador propio (no hay columna dedicada: la línea
                // vive dentro del JSON) — único punto de referencia estable
                // para poder editarla/eliminarla después.
                'id' => (string) Str::uuid(),
                'codigo' => $concepto->codigo,
                'monto' => $montoRedondeado,
                'base_utilizada' => null,
                'tasa_aplicada' => null,
                'cantidad' => null,
                'motivo' => $motivo,
                'formula_texto' => 'Concepto manual agregado a la complementaria'.(filled($motivo) ? " — {$motivo}" : ''),
                // BONIFICACION/BONO_NO_REMUNERATIVO llegan con una
                // clasificación PLAME concreta (ver validación del
                // controller) — PlameCicloDatosLoader la lee para resolver
                // el codigo_plame_snapshot correcto, nunca el genérico.
                'concepto_definicion_id' => $conceptoDefinicionId,
                // Marca que distingue una línea agregada a mano de las que
                // produjo el motor de cálculo — es lo que permite listarlas/
                // eliminarlas por separado (ver eliminarConcepto()).
                'agregado_por' => $usuarioId,
                'agregado_en' => now()->toDateTimeString(),
            ];
            $this->recalcularTotalesSnapshot($snapshot);
            $this->aplicarDeltaDetalle($detalle, $bloque, $montoRedondeado, 1, $snapshot);
        });

        return $this->cargar($item);
    }

    /**
     * Elimina un concepto manual agregado por error — únicamente mientras la
     * complementaria siga "calculada", igual que agregarConcepto(). Nunca
     * toca una línea que produjo el motor de cálculo (solo las marcadas con
     * `agregado_por`): no existe forma de "eliminar" un descuento por falta
     * real, solo de corregir la asistencia y volver a calcular.
     */
    public function eliminarConcepto(Empresa $empresa, PlanillaComplementariaDetalle $detalle, string $lineaId): PlanillaComplementaria
    {
        $item = $detalle->complementaria;
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden eliminar conceptos mientras la complementaria esté calculada, antes de aprobarla.']);
        }

        DB::transaction(function () use ($detalle, $lineaId) {
            $snapshot = $detalle->calculo_snapshot;
            [$bloque, $linea] = $this->buscarLineaManual($snapshot, $lineaId);

            if (! $linea) {
                throw ValidationException::withMessages(['linea' => 'El concepto ya no existe o no fue agregado manualmente — no se puede eliminar un concepto calculado automáticamente.']);
            }

            $snapshot[$bloque] = collect($snapshot[$bloque])
                ->reject(fn (array $l) => ($l['id'] ?? null) === $lineaId)
                ->values()->all();

            $this->recalcularTotalesSnapshot($snapshot);
            $this->aplicarDeltaDetalle($detalle, $bloque, (float) $linea['monto'], -1, $snapshot);
        });

        return $this->cargar($item);
    }

    /**
     * @return array{0: ?string, 1: ?array} [bloque, línea] o [null, null] si
     *   no se encontró o no era una línea agregada manualmente.
     */
    private function buscarLineaManual(array $snapshot, string $lineaId): array
    {
        foreach (['ingresos', 'egresos'] as $bloque) {
            foreach ($snapshot[$bloque] ?? [] as $linea) {
                if (($linea['id'] ?? null) === $lineaId && isset($linea['agregado_por'])) {
                    return [$bloque, $linea];
                }
            }
        }

        return [null, null];
    }

    private function recalcularTotalesSnapshot(array &$snapshot): void
    {
        $snapshot['total_ingresos'] = round(collect($snapshot['ingresos'] ?? [])->sum('monto'), 2);
        $snapshot['total_egresos'] = round(collect($snapshot['egresos'] ?? [])->sum('monto'), 2);
        $snapshot['neto_a_pagar'] = round($snapshot['total_ingresos'] - $snapshot['total_egresos'], 2);
    }

    /**
     * Aplica (dirección +1) o revierte (dirección -1) el efecto de una línea
     * de monto $monto en $bloque ('ingresos'/'egresos') sobre los
     * acumuladores de diferencia del detalle — misma cuenta que
     * agregarConcepto() usaba en línea, ahora compartida con
     * eliminarConcepto() para que ambas nunca diverjan.
     */
    private function aplicarDeltaDetalle(PlanillaComplementariaDetalle $detalle, string $bloque, float $monto, int $direccion, array $snapshot): void
    {
        $esIngreso = $bloque === 'ingresos';
        $deltaBloque = $direccion * $monto;
        $deltaNeto = $direccion * ($esIngreso ? 1 : -1) * $monto;

        $detalle->update([
            'calculo_snapshot' => $snapshot,
            'neto_recalculado' => bcadd((string) $detalle->neto_recalculado, (string) $deltaNeto, 2),
            'diferencia_ingresos' => $esIngreso ? bcadd((string) $detalle->diferencia_ingresos, (string) $deltaBloque, 2) : $detalle->diferencia_ingresos,
            'diferencia_egresos' => ! $esIngreso ? bcadd((string) $detalle->diferencia_egresos, (string) $deltaBloque, 2) : $detalle->diferencia_egresos,
            'diferencia_neta' => bcadd((string) $detalle->diferencia_neta, (string) $deltaNeto, 2),
        ]);
    }

    public function aprobar(Empresa $empresa, PlanillaComplementaria $item, int $usuarioId): PlanillaComplementaria
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede aprobar una complementaria calculada.']);
        }
        $item->update(['estado' => 'aprobada', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);
        return $this->cargar($item);
    }

    /**
     * Elimina por completo una complementaria creada por error — solo
     * mientras siga "calculada": una vez aprobada representa un compromiso
     * de pago/descuento ya confirmado (y puede haber sido exportada al banco
     * o incluida en un PLAME), así que a partir de ahí ya no se puede
     * borrar, solo seguir su flujo normal. `PlanillaComplementariaDetalle`
     * se elimina en cascada (constraint de la migración) — no hace falta
     * borrarlo aparte.
     */
    public function eliminar(Empresa $empresa, PlanillaComplementaria $item): void
    {
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede eliminar una complementaria calculada — una ya aprobada o pagada no se puede borrar, solo seguir su flujo normal.']);
        }

        $item->delete();
    }

    public function marcarPagada(Empresa $empresa, PlanillaComplementaria $item, int $usuarioId, string $referencia): PlanillaComplementaria
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede pagar una complementaria aprobada.']);
        }
        $item->update(['estado' => 'pagada', 'pagado_por' => $usuarioId, 'pagado_at' => now(), 'referencia_pago' => $referencia]);
        return $this->cargar($item);
    }

    public function boletasDePago(Empresa $empresa, PlanillaComplementaria $item, string $subtipo): Collection
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'Aprueba la complementaria antes de generar el archivo bancario.']);
        }

        $esCuarta = $subtipo === '4';
        $detalles = $item->detalles()->where('diferencia_neta', '>', 0)
            ->with(['boletaOriginal.colaborador'])->get()
            ->filter(fn ($d) => ($d->boletaOriginal->regimen_laboral_snapshot === 'Locacion de Servicios') === $esCuarta);

        if ($detalles->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'No existen diferencias positivas para la categoría seleccionada.']);
        }

        return $detalles->map(function ($detalle) {
            $boleta = $detalle->boletaOriginal->replicate();
            $boleta->id = $detalle->boleta_original_id;
            $boleta->neto_a_pagar = $detalle->diferencia_neta;
            $boleta->setRelation('colaborador', $detalle->boletaOriginal->colaborador);
            $datosPago = new BoletaDatosPago([
                'banco_id' => $detalle->banco_id,
                'tipo_cuenta_snapshot' => $detalle->tipo_cuenta_snapshot,
                'moneda_snapshot' => $detalle->moneda_snapshot,
                'numero_cuenta_snapshot' => $detalle->numero_cuenta_snapshot,
                'cci_snapshot' => $detalle->cci_snapshot,
                'fecha_snapshot' => $detalle->created_at,
            ]);
            $datosPago->setRelation('banco', \App\Modules\Configuracion\Models\Banco::find($detalle->banco_id));
            $boleta->setRelation('datosPago', $datosPago);
            return $boleta;
        })->values();
    }

    public function exportarBcp(Empresa $empresa, PlanillaComplementaria $item, $cuenta, string $fechaProceso, string $subtipo): string
    {
        return TelecreditoBcpTxtExporter::generar($cuenta, str_replace('-', '', $fechaProceso), $subtipo, 'COMPLEMENTARIA '.$item->id, $this->boletasDePago($empresa, $item, $subtipo));
    }

    public function exportarBbva(Empresa $empresa, PlanillaComplementaria $item, $cuenta, string $subtipo): string
    {
        return BbvaNetCashTxtExporter::generar($cuenta, $subtipo, 'COMPLEMENTARIA '.$item->id, $this->boletasDePago($empresa, $item, $subtipo));
    }

    private function cargar(PlanillaComplementaria $item): PlanillaComplementaria
    {
        return $item->load(['detalles.colaborador:id,nombres,apellidos,numero_documento', 'detalles.boletaOriginal.datosPago.banco']);
    }

    private function verificar(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) throw new AuthorizationException('El ciclo no pertenece a la empresa autorizada.');
    }

    private function verificarItem(Empresa $empresa, PlanillaComplementaria $item): void
    {
        if ($item->empresa_id !== $empresa->id) throw new AuthorizationException('La complementaria no pertenece a la empresa autorizada.');
    }
}
