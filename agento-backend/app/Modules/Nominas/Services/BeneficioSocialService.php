<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gratificación/CTS (Sección "CTS y Gratificaciones" acordada con el
 * usuario) — SIEMPRE deriva sus montos sumando las líneas ya calculadas en
 * boleta_conceptos (GRATIFICACION_LEGAL/BONIFICACION_EXTRAORDINARIA/
 * CTS_PROVISION), nunca una fórmula paralela: sumar las provisiones
 * mensuales de 1/6 del sueldo a lo largo del semestre da exactamente el
 * monto legal completo, y solo sumar los meses con boleta ya calculada
 * prorratea automáticamente a quien no trabajó el semestre entero.
 *
 * "Calcular" congela esa suma en un snapshot versionado (mismo patrón que
 * Boleta): un recálculo nunca sobrescribe, apaga la versión anterior y crea
 * una nueva.
 */
class BeneficioSocialService
{
    /**
     * Períodos fijos de gratificación/CTS de la ley peruana — fechas del
     * calendario legal, no datos de la empresa, igual que los feriados
     * oficiales.
     *
     * @return array{inicio: string, fin: string, vence: string, codigos: array<int, string>}
     */
    public static function rango(string $tipo, int $anio): array
    {
        return match ($tipo) {
            'gratificacion_julio' => [
                'inicio' => "{$anio}-01-01", 'fin' => "{$anio}-06-30", 'vence' => "{$anio}-07-15",
                'codigos' => ['GRATIFICACION_LEGAL', 'BONIFICACION_EXTRAORDINARIA'],
            ],
            'gratificacion_diciembre' => [
                'inicio' => "{$anio}-07-01", 'fin' => "{$anio}-12-31", 'vence' => "{$anio}-12-15",
                'codigos' => ['GRATIFICACION_LEGAL', 'BONIFICACION_EXTRAORDINARIA'],
            ],
            'cts_mayo' => [
                'inicio' => Carbon::create($anio - 1, 11, 1)->toDateString(), 'fin' => "{$anio}-04-30", 'vence' => "{$anio}-05-15",
                'codigos' => ['CTS_PROVISION'],
            ],
            'cts_noviembre' => [
                'inicio' => "{$anio}-05-01", 'fin' => "{$anio}-10-31", 'vence' => "{$anio}-11-15",
                'codigos' => ['CTS_PROVISION'],
            ],
            default => throw ValidationException::withMessages(['tipo' => 'Tipo de beneficio no reconocido.']),
        };
    }

    /**
     * Vista para la pantalla: si ya existe un snapshot vigente calculado
     * para esta empresa+tipo+año, se muestra ESE (congelado, reproducible).
     * Si todavía no se ha calculado ninguno, se muestra una vista previa en
     * vivo (misma suma, sin persistir) para que RR.HH. sepa qué esperar
     * antes de darle a "Calcular".
     *
     * @return array{colaboradores: array<int, array>, total_colaboradores: int, total_bruto: float, total_neto: float, pendientes_de_pago: int, vence: string, calculado: bool, estado: ?string, calculado_at: ?string}
     */
    public function resumen(Empresa $empresa, string $tipo, int $anio): array
    {
        $vigente = BeneficioSocial::where('empresa_id', $empresa->id)
            ->where('tipo', $tipo)->where('anio', $anio)
            ->where('es_version_vigente', true)
            ->with('detalles.colaborador')
            ->first();

        $rango = self::rango($tipo, $anio);

        if ($vigente) {
            return [
                'id' => $vigente->id,
                'colaboradores' => $vigente->detalles->map(fn (BeneficioSocialDetalle $detalle) => [
                    'colaborador_id' => $detalle->colaborador_id,
                    'colaborador' => trim("{$detalle->colaborador->nombres} {$detalle->colaborador->apellidos}"),
                    'legajo' => $detalle->colaborador->legajo,
                    'empresa' => $empresa->nombre_comercial,
                    'sueldo_basico' => (float) $detalle->sueldo_basico,
                    'meses' => $detalle->meses,
                    'bruta' => (float) $detalle->bruta,
                    'bonificacion_extraordinaria' => (float) $detalle->bonificacion_extraordinaria,
                    'neta' => (float) $detalle->neta,
                    'estado' => $vigente->estado,
                ])->values()->all(),
                'total_colaboradores' => $vigente->total_colaboradores,
                'total_bruto' => (float) $vigente->total_bruto,
                'total_neto' => (float) $vigente->total_neto,
                'pendientes_de_pago' => $vigente->estado === 'pagado' ? 0 : $vigente->total_colaboradores,
                'vence' => $rango['vence'],
                'calculado' => true,
                'estado' => $vigente->estado,
                'version' => $vigente->version,
                'calculado_at' => $vigente->calculado_at->toDateTimeString(),
            ];
        }

        $vistaPrevia = $this->calcularEnVivo($empresa, $tipo, $anio);

        return [
            ...$vistaPrevia,
            'colaboradores' => collect($vistaPrevia['colaboradores'])->map(fn ($fila) => [...$fila, 'estado' => 'sin_calcular'])->all(),
            'pendientes_de_pago' => $vistaPrevia['total_colaboradores'],
            'vence' => $rango['vence'],
            'calculado' => false,
            'estado' => null,
            'version' => null,
            'calculado_at' => null,
        ];
    }

    /**
     * @throws ValidationException si no hay ningún colaborador con boletas en el período
     */
    public function calcular(Empresa $empresa, string $tipo, int $anio, int $usuarioId): BeneficioSocial
    {
        $vistaPrevia = $this->calcularEnVivo($empresa, $tipo, $anio);

        if ($vistaPrevia['total_colaboradores'] === 0) {
            throw ValidationException::withMessages([
                'tipo' => 'No hay boletas calculadas de ningún colaborador en este período — calcula primero la planilla mensual correspondiente.',
            ]);
        }

        return DB::transaction(function () use ($empresa, $tipo, $anio, $usuarioId, $vistaPrevia) {
            $anterior = BeneficioSocial::where('empresa_id', $empresa->id)
                ->where('tipo', $tipo)->where('anio', $anio)
                ->where('es_version_vigente', true)
                ->first();

            $anterior?->update(['es_version_vigente' => false]);

            $beneficio = BeneficioSocial::create([
                'empresa_id' => $empresa->id,
                'tipo' => $tipo,
                'anio' => $anio,
                'version' => ($anterior?->version ?? 0) + 1,
                'es_version_vigente' => true,
                'total_colaboradores' => $vistaPrevia['total_colaboradores'],
                'total_bruto' => $vistaPrevia['total_bruto'],
                'total_neto' => $vistaPrevia['total_neto'],
                'estado' => 'calculado',
                'calculado_por' => $usuarioId,
                'calculado_at' => now(),
            ]);

            foreach ($vistaPrevia['colaboradores'] as $fila) {
                BeneficioSocialDetalle::create([
                    'beneficio_social_id' => $beneficio->id,
                    'colaborador_id' => $fila['colaborador_id'],
                    'sueldo_basico' => $fila['sueldo_basico'],
                    'meses' => $fila['meses'],
                    'bruta' => $fila['bruta'],
                    'bonificacion_extraordinaria' => $fila['bonificacion_extraordinaria'],
                    'neta' => $fila['neta'],
                ]);
            }

            return $beneficio->load('detalles.colaborador');
        });
    }

    /**
     * "Pagado" exige una referencia real, igual que en Boleta — nunca un
     * badge sin respaldo.
     */
    public function marcarPagado(Empresa $empresa, BeneficioSocial $beneficio, int $usuarioId, string $referenciaPago): BeneficioSocial
    {
        if ($beneficio->empresa_id !== $empresa->id) {
            throw ValidationException::withMessages(['empresa' => 'Este beneficio no pertenece a la empresa activa.']);
        }

        if ($beneficio->estado !== 'calculado') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede marcar como pagado un beneficio en estado "calculado".']);
        }

        $beneficio->update([
            'estado' => 'pagado',
            'pagado_por' => $usuarioId,
            'pagado_at' => now(),
            'referencia_pago' => $referenciaPago,
        ]);

        return $beneficio;
    }

    /**
     * @return array{colaboradores: array<int, array>, total_colaboradores: int, total_bruto: float, total_neto: float}
     */
    private function calcularEnVivo(Empresa $empresa, string $tipo, int $anio): array
    {
        $rango = self::rango($tipo, $anio);

        $conceptos = BoletaConcepto::whereHas('concepto', fn ($q) => $q->whereIn('codigo', $rango['codigos']))
            ->whereHas('boleta', function ($q) use ($empresa, $rango) {
                $q->where('empresa_id', $empresa->id)
                    ->where('es_version_vigente', true)
                    ->whereHas('ciclo', fn ($q2) => $q2->whereDate('fecha_inicio', '>=', $rango['inicio'])->whereDate('fecha_fin', '<=', $rango['fin']));
            })
            ->with(['boleta.colaborador', 'concepto'])
            ->get();

        $ajustesPorColaborador = $this->ajustesComplementariasPorCodigo($empresa, $rango['codigos'], $rango['inicio'], $rango['fin']);

        $porColaborador = $conceptos->groupBy('boleta.colaborador_id')->map(function ($lineas) use ($empresa, $ajustesPorColaborador) {
            $boleta = $lineas->first()->boleta;
            $colaborador = $boleta->colaborador;
            $ajustes = $ajustesPorColaborador->get($colaborador->id, []);

            $bruta = $lineas->where('concepto.codigo', 'GRATIFICACION_LEGAL')->sum('monto') + ($ajustes['GRATIFICACION_LEGAL'] ?? 0.0)
                + $lineas->where('concepto.codigo', 'CTS_PROVISION')->sum('monto') + ($ajustes['CTS_PROVISION'] ?? 0.0);
            $bonificacionExtraordinaria = $lineas->where('concepto.codigo', 'BONIFICACION_EXTRAORDINARIA')->sum('monto') + ($ajustes['BONIFICACION_EXTRAORDINARIA'] ?? 0.0);
            $meses = $lineas->pluck('boleta_id')->unique()->count();

            return [
                'colaborador_id' => $colaborador->id,
                'colaborador' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'legajo' => $colaborador->legajo,
                'empresa' => $empresa->nombre_comercial,
                'sueldo_basico' => (float) $boleta->sueldo_basico_snapshot,
                'meses' => $meses,
                'bruta' => round($bruta, 2),
                'bonificacion_extraordinaria' => round($bonificacionExtraordinaria, 2),
                'neta' => round($bruta + $bonificacionExtraordinaria, 2),
            ];
        })->values();

        return [
            'colaboradores' => $porColaborador->all(),
            'total_colaboradores' => $porColaborador->count(),
            'total_bruto' => round($porColaborador->sum('bruta'), 2),
            'total_neto' => round($porColaborador->sum('neta'), 2),
        ];
    }

    /**
     * Ajuste neto por colaborador y por código ($codigos, ej.
     * GRATIFICACION_LEGAL/BONIFICACION_EXTRAORDINARIA o CTS_PROVISION según
     * $tipo) que aportan las planillas complementarias aprobadas/pagadas de
     * boletas dentro del semestre — sin esto, un ingreso remunerativo
     * agregado después de pagar (PlanillaComplementariaService::
     * recalcularProvisiones()) nunca llegaría al cálculo real de CTS/
     * gratificación, porque este servicio SIEMPRE lee boleta_conceptos
     * directo (nunca planillas_complementarias) por diseño (ver docblock de
     * la clase). Se calcula como (snapshot corregido − boleta original) por
     * código, nunca el valor absoluto del snapshot, para no descontar dos
     * veces lo que boleta_conceptos ya aporta arriba.
     *
     * @param  array<int, string>  $codigos
     * @return Collection<int, array<string, float>> indexada por colaborador_id
     */
    private function ajustesComplementariasPorCodigo(Empresa $empresa, array $codigos, string $inicio, string $fin): Collection
    {
        $detalles = PlanillaComplementariaDetalle::whereHas(
            'complementaria',
            fn ($q) => $q->where('empresa_id', $empresa->id)->whereIn('estado', ['aprobada', 'pagada']),
        )
            ->whereHas('boletaOriginal', fn ($q) => $q->whereHas(
                'ciclo',
                fn ($q2) => $q2->whereDate('fecha_inicio', '>=', $inicio)->whereDate('fecha_fin', '<=', $fin),
            ))
            ->with('boletaOriginal.conceptos.concepto')
            ->get();

        if ($detalles->isEmpty()) {
            return collect();
        }

        return $detalles->groupBy('colaborador_id')->map(function (Collection $grupo) use ($codigos) {
            $porCodigo = [];
            foreach ($codigos as $codigo) {
                $nuevo = $grupo->sum(fn (PlanillaComplementariaDetalle $d) => collect($d->calculo_snapshot['aportaciones'] ?? [])
                    ->where('codigo', $codigo)->sum('monto'));
                $original = $grupo->sum(fn (PlanillaComplementariaDetalle $d) => $d->boletaOriginal->conceptos
                    ->filter(fn ($c) => $c->concepto?->codigo === $codigo)->sum('monto'));
                $porCodigo[$codigo] = round($nuevo - $original, 2);
            }

            return $porCodigo;
        });
    }
}
