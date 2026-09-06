<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DescansoSemanalComplementariaService
{
    public function __construct(private readonly PlanillaComplementariaService $complementarias) {}

    public function semanas(Empresa $empresa, CicloRemunerativo $ciclo, array $ids): array
    {
        $boletas = $this->complementarias->boletasParaReintegro($empresa, $ciclo, $ids);
        $filas = [];
        foreach ($boletas as $boleta) {
            $colaborador = $boleta->colaborador;
            $detalles = PlanillaComplementariaDetalle::where('colaborador_id', $colaborador->id)
                ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['calculada', 'aprobada', 'pagada']))->with('complementaria')->get();
            $reservadas = $detalles->flatMap(fn ($d) => $d->calculo_snapshot['descansos_semanales'] ?? [])->pluck('semana_inicio')->all();
            $pendiente = $detalles->contains(fn ($d) => $d->boleta_original_id === $boleta->id && $d->complementaria->estado !== 'pagada');
            $base = $this->complementarias->baseParaReintegro($boleta);
            // Las boletas históricas no siempre atribuyen HE_100 a una fecha.
            $hePagadas = collect($base['ingresos'] ?? [])->where('codigo', 'HE_100')->sum('monto');
            $descansosPagados = $detalles->filter(fn ($d) => $d->complementaria->estado === 'pagada')
                ->flatMap(fn ($d) => $d->calculo_snapshot['descansos_semanales'] ?? [])
                ->unique('semana_inicio')->sum('importe_bruto');
            $pagoSinFecha = round($hePagadas - $descansosPagados, 2) > 0;
            for ($inicio = $ciclo->fecha_inicio->copy()->startOfWeek(Carbon::MONDAY); $inicio->copy()->addDays(6)->lte($ciclo->fecha_fin); $inicio->addWeek()) {
                $fin = $inicio->copy()->addDays(6);
                $desde = $inicio->toDateString();
                $hasta = $fin->toDateString();
                if (in_array($desde, $reservadas, true)) continue;
                $dias = AsistenciaResultadoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)->get();
                if ($dias->pluck('fecha')->map(fn ($f) => $f->toDateString())->unique()->count() !== 7
                    || $dias->contains(fn ($d) => ! in_array($d->estado, ['presente', 'horario_desplazado', 'horas_incompletas'], true) || $d->minutos_trabajados <= 0)) continue;
                $asignacion = ColaboradorHorarioAsignacion::withoutGlobalScopes()->where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)->whereDate('vigencia_desde', '<=', $desde)
                    ->where(fn ($q) => $q->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $hasta))
                    ->latest('vigencia_desde')->with('horario')->first();
                $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                    ->whereDate('vigencia_desde', '<=', $desde)->latest('vigencia_desde')->latest('id')->first();
                $heAprobada = AsistenciaHoraExtra::withoutGlobalScopes()->where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)
                    ->where('tasa', '100')->whereIn('estado', ['pendiente', 'aprobado'])->exists();
                $sustitutorio = ColaboradorCalendarioDia::where('colaborador_id', $colaborador->id)
                    ->where('origen', ColaboradorCalendarioDia::ORIGEN_DESCANSO_SUSTITUTORIO)
                    ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $fin->copy()->addDays(6)->toDateString())->exists();
                $motivo = match (true) {
                    $boleta->regimen_laboral_snapshot === 'Locacion de Servicios' => 'No aplica a recibos por honorarios.',
                    ! $asignacion || (int) $asignacion->dias_descanso_rotativo_por_semana !== 1 => 'Requiere una asignación vigente con un descanso rotativo semanal.',
                    $pendiente => 'Tiene una complementaria pendiente: completa su pago o elimina el borrador.',
                    $sustitutorio => 'Hay un descanso sustitutorio registrado en esta semana o en los seis días siguientes. Revisa a qué jornada corresponde.',
                    $heAprobada || $pagoSinFecha => 'Hay horas al 100% pendientes, aprobadas o pagadas. Revisa su origen para evitar duplicar el pago.',
                    ! $remuneracion || $remuneracion->periodicidad_pago !== 'mensual' || $remuneracion->salario <= 0 => 'Falta remuneración mensual histórica válida.',
                    ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)->whereDate('vigencia_desde', '>', $desde)->whereDate('vigencia_desde', '<=', $hasta)->exists() => 'La remuneración cambió durante la semana; requiere revisión específica.',
                    default => null,
                };
                $monto = round(((float) ($remuneracion?->salario ?? 0) / 30) * 2, 2);
                $filas[] = ['boleta_id' => $boleta->id, 'colaborador' => trim($colaborador->nombres.' '.$colaborador->apellidos),
                    'semana_inicio' => $desde, 'semana_fin' => $hasta, 'dias_trabajados' => 7, 'dias_reintegrar' => 1,
                    'sueldo' => $remuneracion?->salario, 'importe_bruto' => number_format($monto, 2, '.', ''),
                    'disponible' => $motivo === null, 'observacion' => $motivo];
            }
        }
        return $filas;
    }

    public function crear(Empresa $empresa, CicloRemunerativo $ciclo, array $seleccion, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $seleccion, $motivo, $usuarioId) {
            $ciclo = CicloRemunerativo::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();
            $ids = collect($seleccion)->pluck('boleta_id')->unique()->all();
            $boletas = $this->complementarias->boletasParaReintegro($empresa, $ciclo, $ids);
            Colaborador::withoutGlobalScopes()->whereIn('id', $boletas->pluck('colaborador_id'))->orderBy('id')->lockForUpdate()->get();
            $disponibles = collect($this->semanas($empresa, $ciclo, $ids))->where('disponible', true)
                ->keyBy(fn ($s) => $s['boleta_id'].'/'.$s['semana_inicio']);
            $elegidas = [];
            foreach ($seleccion as $s) {
                $key = $s['boleta_id'].'/'.$s['semana_inicio'];
                if (isset($elegidas[$key]) || ! $disponibles->has($key)) {
                    throw ValidationException::withMessages(['semanas' => 'Una semana ya fue incluida, cambió o no cumple los requisitos. Actualiza la lista.']);
                }
                $elegidas[$key] = $disponibles[$key];
            }
            $item = PlanillaComplementaria::create(['ciclo_id' => $ciclo->id, 'empresa_id' => $empresa->id,
                'nombre' => 'Descansos semanales trabajados '.$ciclo->nombre, 'motivo' => $motivo, 'estado' => 'calculada', 'creado_por' => $usuarioId]);
            $concepto = ConceptoRemuneracion::where('codigo', 'HE_100')->where('activo', true)->firstOrFail();
            foreach ($boletas as $boleta) {
                $base = $this->complementarias->baseParaReintegro($boleta);
                unset($base['descansos_semanales'], $base['reintegros_descuentos']);
                if (! collect($base['egresos'] ?? [])->contains('codigo', 'RENTA_5TA')) {
                    $base['egresos'][] = ['codigo' => 'RENTA_5TA', 'monto' => 0, 'base_utilizada' => $base['total_ingresos']];
                }
                $c = $boleta->colaborador;
                $detalle = PlanillaComplementariaDetalle::create(['planilla_complementaria_id' => $item->id,
                    'boleta_original_id' => $boleta->id, 'colaborador_id' => $c->id, 'banco_id' => $c->banco_id,
                    'tipo_cuenta_snapshot' => $c->tipo_cuenta, 'moneda_snapshot' => $c->moneda_cuenta,
                    'numero_cuenta_snapshot' => $c->numero_cuenta, 'cci_snapshot' => $c->cci,
                    'neto_original' => $base['neto_a_pagar'], 'neto_recalculado' => $base['neto_a_pagar'],
                    'diferencia_ingresos' => 0, 'diferencia_egresos' => 0, 'diferencia_aportaciones' => 0,
                    'diferencia_neta' => 0, 'calculo_snapshot' => $base]);
                $semanas = collect($elegidas)->where('boleta_id', $boleta->id)->values()->all();
                $total = collect($semanas)->reduce(fn ($acc, $s) => bcadd($acc, $s['importe_bruto'], 2), '0.00');
                $this->complementarias->agregarConcepto($empresa, $detalle, $concepto->id, null, (float) $total,
                    'Descanso semanal trabajado sin sustitutorio ni pago previo. '.$motivo, $usuarioId);
                $snapshot = $detalle->fresh()->calculo_snapshot;
                $snapshot['descansos_semanales'] = $semanas;
                $snapshot['confirmacion_descansos'] = ['sin_sustitutorio' => true, 'sin_pago_previo' => true, 'usuario_id' => $usuarioId, 'fecha' => now()->toDateTimeString()];
                $detalle->update(['calculo_snapshot' => $snapshot]);
            }
            return $item->load(['detalles.colaborador', 'detalles.boletaOriginal.datosPago.banco']);
        });
    }
}
