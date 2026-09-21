<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Fase A.1 del endurecimiento Asistencia → Nómina (plan "Descanso semanal
 * flexible automático", v5) — hoy `CalcularBoletaColaborador` nunca consulta
 * `AsistenciaPeriodo` en absoluto: un `CicloRemunerativo` se puede calcular,
 * cerrar y pagar mientras su período de asistencia sigue abierto o incluso
 * inexistente. Esta clase es el primer punto donde Nómina realmente verifica
 * ese terreno antes de avanzar.
 *
 * Este incremento cubre solo 3 de los 5 chequeos de A.1 (cobertura de
 * asistencia, período asociado, período abierto). Incidencias bloqueantes y
 * horas extra pendientes se incorporan en el incremento 4; `requiere_recalculo`
 * ya lo verifica directamente CicloRemunerativoService::marcarPagado() desde
 * el incremento 3 — a propósito, para poder probar cada pieza por separado
 * antes de combinarlas.
 *
 * Todas las comparaciones de fecha usan `>=`/`<` directos sobre la columna,
 * nunca `whereBetween()` ni `whereDate()` con un límite superior inclusivo:
 * envolver la columna en una función (whereDate) impide que MySQL aproveche
 * su índice, y comparar como texto contra un límite superior inclusivo
 * (whereBetween) puede excluir el último día si el driver persiste `fecha`
 * con componente de hora (confirmado en SQLite: '2026-07-02 00:00:00' no es
 * `<=` '2026-07-02' en una comparación de texto). Un límite superior
 * EXCLUSIVO un día después sí es seguro y aprovechable por índice en ambos
 * motores.
 */
class VerificarConsistenciaAsistenciaCiclo
{
    /**
     * @throws ValidationException
     */
    public function verificar(Empresa $empresa, string $fechaInicio, string $fechaFin): void
    {
        $fechasSinPeriodo = $this->fechasSinPeriodoAsociado($empresa, $fechaInicio, $fechaFin);
        if ($fechasSinPeriodo !== []) {
            $listado = implode(', ', array_slice($fechasSinPeriodo, 0, 5));
            $extra = count($fechasSinPeriodo) > 5 ? ' y '.(count($fechasSinPeriodo) - 5).' fecha(s) más' : '';
            throw ValidationException::withMessages([
                'asistencia' => ["Las fechas {$listado}{$extra} no pertenecen a ningún período de asistencia. Crea y cierra el período correspondiente antes de calcular."],
            ]);
        }

        $finExclusivo = $this->finExclusivo($fechaFin);

        $periodoAbierto = AsistenciaPeriodo::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'abierto')
            ->where('fecha_inicio', '<', $finExclusivo)
            ->where('fecha_fin', '>=', $fechaInicio)
            ->first();
        if ($periodoAbierto) {
            throw ValidationException::withMessages([
                'asistencia' => ["El período de asistencia {$periodoAbierto->fecha_inicio->toDateString()} – {$periodoAbierto->fecha_fin->toDateString()} sigue abierto. Ciérralo o envíalo a Nómina antes de calcular este ciclo."],
            ]);
        }

        if ($this->tieneColaboradoresSinCobertura($empresa, $fechaInicio, $fechaFin)) {
            throw ValidationException::withMessages([
                'asistencia' => ['Hay colaboradores sin asistencia procesada entre '.$fechaInicio.' y '.$fechaFin.'. Procesa la asistencia antes de calcular el ciclo.'],
            ]);
        }
    }

    /** Límite superior EXCLUSIVO (un día después) -- ver docblock de la clase. */
    private function finExclusivo(string $fecha): string
    {
        return Carbon::parse($fecha)->addDay()->toDateString();
    }

    /** @return array<int, string> */
    private function fechasSinPeriodoAsociado(Empresa $empresa, string $fechaInicio, string $fechaFin): array
    {
        $periodos = AsistenciaPeriodo::query()
            ->where('empresa_id', $empresa->id)
            ->where('fecha_inicio', '<', $this->finExclusivo($fechaFin))
            ->where('fecha_fin', '>=', $fechaInicio)
            ->get(['fecha_inicio', 'fecha_fin']);

        $faltantes = [];
        for ($fecha = Carbon::parse($fechaInicio); $fecha->lte(Carbon::parse($fechaFin)); $fecha->addDay()) {
            $cubierta = $periodos->contains(fn (AsistenciaPeriodo $periodo) => $fecha->gte($periodo->fecha_inicio) && $fecha->lte($periodo->fecha_fin));
            if (! $cubierta) {
                $faltantes[] = $fecha->toDateString();
            }
        }

        return $faltantes;
    }

    /**
     * Mismo criterio de elegibilidad que AsegurarCoberturaAsistenciaPeriodo::
     * detectarFaltantes() (sin where('activo', true) a propósito -- un
     * colaborador cesado igual necesita cobertura para las fechas anteriores
     * a su cese), pero simplificado a un booleano con corte temprano: acá
     * solo hace falta saber SI falta algo, no enumerar cada combinación.
     */
    private function tieneColaboradoresSinCobertura(Empresa $empresa, string $fechaInicio, string $fechaFin): bool
    {
        $finExclusivo = $this->finExclusivo($fechaFin);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('fecha_ingreso', '<', $finExclusivo)
            ->where(fn ($query) => $query->whereNull('fecha_cese')->orWhere('fecha_cese', '>=', $fechaInicio))
            ->get(['id', 'fecha_ingreso', 'fecha_cese']);

        if ($colaboradores->isEmpty()) {
            return false;
        }

        $inicioRango = Carbon::parse($fechaInicio);
        $finRango = Carbon::parse($fechaFin);

        $cubiertasPorColaborador = AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->where('fecha', '>=', $fechaInicio)
            ->where('fecha', '<', $finExclusivo)
            ->get(['colaborador_id', 'fecha'])
            ->groupBy('colaborador_id')
            ->map(fn ($grupo) => $grupo->pluck('fecha')->map(fn (Carbon $fecha) => $fecha->toDateString())->flip());

        foreach ($colaboradores as $colaborador) {
            $inicioVigente = $colaborador->fecha_ingreso->greaterThan($inicioRango) ? $colaborador->fecha_ingreso->copy() : $inicioRango->copy();
            $finVigente = $colaborador->fecha_cese?->lessThan($finRango) ? $colaborador->fecha_cese->copy() : $finRango->copy();
            $yaCubiertas = $cubiertasPorColaborador->get($colaborador->id);

            for ($fecha = $inicioVigente->copy(); $fecha->lte($finVigente); $fecha->addDay()) {
                if ($yaCubiertas === null || ! $yaCubiertas->has($fecha->toDateString())) {
                    return true;
                }
            }
        }

        return false;
    }
}
