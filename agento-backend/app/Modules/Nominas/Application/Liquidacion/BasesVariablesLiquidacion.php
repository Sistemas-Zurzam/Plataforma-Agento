<?php

namespace App\Modules\Nominas\Application\Liquidacion;

use App\Modules\Nominas\Domain\Liquidacion\DiasComerciales;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Remuneraciones variables que integran la base de cada beneficio de la
 * liquidación (horas extra, comisiones, bonos), leídas de la planilla del
 * vínculo.
 *
 * Fuente por mes: la versión final de cada boleta vigente — la última
 * planilla complementaria aprobada/pagada de esa boleta (su snapshot es
 * acumulativo) o, si no tiene, la boleta original. El monto se ubica en el
 * mes devengado del ciclo (fecha_inicio), no en la fecha de pago; varios
 * pagos del mismo concepto en un mes cuentan como un solo mes.
 *
 * Reglas (texto oficial):
 * - Complementarias variables: regulares si se perciben al menos 3 meses en
 *   el período de 6; se suman y se dividen entre 6, también si el período a
 *   liquidar es menor a 6 meses (TUO CTS art. 16; Ley 27735 art. 3; D.S.
 *   005-2002-TR num. 3.1).
 * - Remuneración principal imprecisa (comisionista): promedio del semestre;
 *   si el período es menor a 6 meses, promedio diario de lo percibido
 *   (TUO CTS art. 17; aplicable a gratificación por D.S. 005-2002-TR num. 3.1
 *   y a vacaciones por D.S. 012-92-TR art. 17).
 * - Excluidos: conceptos no remunerativos o marcados sin incidencia en el
 *   beneficio (TUO CTS art. 19), y los fijos que ya forman la base (básico,
 *   asignación familiar, remuneración vacacional).
 */
class BasesVariablesLiquidacion
{
    private const FIJOS = ['SUELDO_BASICO', 'ASIGNACION_FAMILIAR', 'REMUNERACION_VACACIONAL'];

    /** @var Collection<string, ConceptoRemuneracion>|null */
    private ?Collection $catalogo = null;

    /**
     * @return array<string, array<string, float>> 'Y-m' => [codigo => monto]
     */
    public function montosPorMes(Colaborador $colaborador, Carbon $desde, Carbon $hasta): array
    {
        $porMes = [];
        foreach ($this->lineasFinales($colaborador, $desde, $hasta)->where('tipo', 'ingreso') as $linea) {
            $porMes[$linea['mes']][$linea['codigo']] = round(($porMes[$linea['mes']][$linea['codigo']] ?? 0) + $linea['monto'], 2);
        }
        ksort($porMes);

        return $porMes;
    }

    /**
     * Ingresos gravados con 5ta y retenciones de 5ta del año en este vínculo,
     * desde la versión final de cada boleta (boleta o última complementaria).
     * $cicloExcluido: la boleta que se está calculando no cuenta como previa.
     *
     * @return array{gravado: float, retenido: float, devuelto: float}
     */
    public function quintaDelAnio(Colaborador $colaborador, int $anio, ?int $cicloExcluido = null): array
    {
        $lineas = $this->lineasFinales($colaborador, Carbon::create($anio, 1, 1), Carbon::create($anio, 12, 31), $cicloExcluido);
        $gravado = $lineas->where('tipo', 'ingreso')->filter(fn ($l) => (bool) $this->concepto($l['codigo'])?->afecta_renta_5ta)->sum('monto');

        // Una devolución de 5ta en una boleta (ajuste del cese) resta de lo retenido.
        $devuelto = round($lineas->where('codigo', 'DEVOLUCION_RENTA_5TA')->sum('monto'), 2);

        return ['gravado' => round($gravado, 2), 'retenido' => round($lineas->where('tipo', 'egreso')->where('codigo', 'RENTA_5TA')->sum('monto') - $devuelto, 2), 'devuelto' => $devuelto];
    }

    /**
     * @return Collection<int, array{mes: string, codigo: string, tipo: string, monto: float}>
     */
    private function lineasFinales(Colaborador $colaborador, Carbon $desde, Carbon $hasta, ?int $cicloExcluido = null): Collection
    {
        $boletas = Boleta::where('colaborador_id', $colaborador->id)
            ->where('es_version_vigente', true)
            ->whereIn('estado', ['calculada', 'aprobada', 'pagada'])
            ->when($cicloExcluido, fn ($q) => $q->where('ciclo_id', '!=', $cicloExcluido))
            ->whereHas('ciclo', fn ($q) => $q->whereDate('fecha_inicio', '>=', $desde->copy()->startOfMonth()->toDateString())
                ->whereDate('fecha_inicio', '<=', $hasta->toDateString()))
            ->with(['ciclo:id,fecha_inicio', 'conceptos.concepto:id,codigo'])
            ->get();

        $complementarias = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletas->pluck('id'))
            ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['aprobada', 'pagada']))
            ->orderBy('id')->get(['id', 'boleta_original_id', 'calculo_snapshot'])
            ->keyBy('boleta_original_id'); // keyBy conserva la última (id mayor)

        $resultado = collect();
        foreach ($boletas as $boleta) {
            $mes = Carbon::parse($boleta->ciclo->fecha_inicio)->format('Y-m');
            if ($snapshot = $complementarias->get($boleta->id)?->calculo_snapshot) {
                $lineas = collect(['ingreso' => $snapshot['ingresos'] ?? [], 'egreso' => $snapshot['egresos'] ?? []])
                    ->flatMap(fn ($bloque, $tipo) => collect($bloque)->map(fn ($l) => ['codigo' => $l['codigo'] ?? null, 'tipo' => $tipo, 'monto' => (float) ($l['monto'] ?? 0)]));
            } else {
                $lineas = $boleta->conceptos->map(fn ($l) => ['codigo' => $l->concepto?->codigo, 'tipo' => $l->tipo, 'monto' => (float) $l->monto]);
            }
            foreach ($lineas as $linea) {
                if ($linea['codigo']) {
                    $resultado->push([...$linea, 'mes' => $mes]);
                }
            }
        }

        return $resultado;
    }

    /**
     * @param  array<string, array<string, float>>  $porMes
     * @param  array{beneficio: string, afectacion: string, desde: Carbon, hasta: Carbon, comision_principal: bool, regla: string}  $config
     * @return array<string, mixed>
     */
    public function calcular(array $porMes, array $config): array
    {
        $desde = $config['desde']->copy()->startOfMonth();
        $hasta = $config['hasta']->copy();
        $meses = [];
        for ($m = $desde->copy(); $m->lte($hasta); $m->addMonthNoOverflow()) {
            $meses[] = $m->format('Y-m');
        }
        // Días comerciales (meses de 30), los mismos con los que se liquida la CTS.
        $diasPeriodo = DiasComerciales::entre($config['desde'], $hasta);

        $porConcepto = [];
        foreach ($meses as $mes) {
            foreach ($porMes[$mes] ?? [] as $codigo => $monto) {
                $porConcepto[$codigo][$mes] = $monto;
            }
        }

        $componentes = [];
        $total = 0.0;
        foreach ($porConcepto as $codigo => $montos) {
            $concepto = $this->concepto($codigo);
            if (! $concepto || in_array($codigo, self::FIJOS, true) || $concepto->tipo !== 'ingreso'
                || ! $concepto->es_remunerativo_laboral || ! $concepto->{$config['afectacion']}) {
                continue;
            }
            $suma = round(array_sum($montos), 2);
            $mesesConPago = count(array_filter($montos, fn ($m) => $m > 0));
            if ($codigo === 'COMISION' && $config['comision_principal']) {
                $naturaleza = 'principal_imprecisa';
                $divisor = $diasPeriodo < 180 ? "{$diasPeriodo} días × 30" : '6';
                $monto = $diasPeriodo < 180 ? $suma / $diasPeriodo * 30 : $suma / 6;
                $regular = true;
            } else {
                $naturaleza = 'complementaria_variable';
                $regular = $mesesConPago >= 3;
                $divisor = '6';
                $monto = $regular ? $suma / 6 : 0.0;
            }
            $monto = round($monto, 2);
            $total += $monto;
            $componentes[] = [
                'codigo' => $codigo, 'nombre' => $concepto->nombre, 'naturaleza' => $naturaleza,
                'meses' => $montos, 'meses_con_pago' => $mesesConPago, 'suma' => $suma,
                'regular' => $regular, 'divisor' => $divisor, 'monto' => $monto,
            ];
        }

        return [
            'beneficio' => $config['beneficio'], 'desde' => $config['desde']->toDateString(), 'hasta' => $hasta->toDateString(),
            'meses_evaluados' => $meses, 'componentes' => $componentes, 'total' => round($total, 2), 'regla' => $config['regla'],
        ];
    }

    private function concepto(string $codigo): ?ConceptoRemuneracion
    {
        $this->catalogo ??= ConceptoRemuneracion::all()->keyBy('codigo');

        return $this->catalogo->get($codigo);
    }
}
