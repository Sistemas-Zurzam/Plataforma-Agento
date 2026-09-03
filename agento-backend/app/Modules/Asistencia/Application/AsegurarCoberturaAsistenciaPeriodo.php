<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fase 4A — antes de cerrar un período, garantiza que TODA combinación
 * colaborador×fecha aplicable ya pasó por ProcesarAsistenciaDiaria.
 * "Cobertura" solo significa que la fecha fue EVALUADA (falta, descanso,
 * permiso, dia_sin_clasificar, etc.) — no que el resultado sea correcto ni
 * esté resuelto; eso sigue siendo trabajo de las incidencias existentes.
 *
 * No crea un segundo motor: reutiliza ProcesarAsistenciaDiaria tal cual
 * (ya es seguro con marcaciones vacías e idempotente vía updateOrCreate +
 * UNIQUE de BD). Solo decide QUÉ combinaciones le faltan procesar.
 *
 * Corre tanto en el request HTTP (contarFaltantes(), para decidir si hace
 * falta encolar algo) como dentro de AsegurarCoberturaAsistenciaPeriodoJob
 * (ejecutar(), el trabajo real) — nunca dentro de un contexto autenticado,
 * por eso TODAS las consultas filtran empresa_id explícitamente
 * (Colaborador no tiene EmpresaScope a propósito — ver su docblock — y
 * EmpresaScope en general no hace nada fuera de una request con JWT).
 */
class AsegurarCoberturaAsistenciaPeriodo
{
    /** Ni una transacción gigante de todo el período ni un procesar() a la vez — un tamaño intermedio razonable. */
    private const TAMANO_CHUNK = 200;

    public function __construct(
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly FechaOperativa $fechaOperativa,
    ) {}

    /**
     * Fase 4A.1/4A.2 — única fuente de verdad de "hoy" para todo lo
     * relacionado a cobertura (rango a procesar Y la validación de "no
     * cerrar con fecha_fin futura" en AsistenciaPeriodoService). Delega en
     * FechaOperativa (timezone operativo, hoy America/Lima) — NO en
     * Carbon::now() crudo, que sigue siendo UTC (config('app.timezone'),
     * sin cambios) y podía adelantar la fecha hasta 5 horas respecto al
     * calendario real de Perú.
     */
    public function hoy(): Carbon
    {
        return $this->fechaOperativa->hoy();
    }

    /**
     * Chequeo barato para decidir si vale la pena encolar el job — nunca
     * invoca ProcesarAsistenciaDiaria, solo dos consultas agregadas.
     */
    public function contarFaltantes(Empresa $empresa, AsistenciaPeriodo $periodo): int
    {
        return count($this->detectarFaltantes($empresa, $periodo));
    }

    /**
     * @return array{procesadas: int, ya_cubiertas: int, faltas: int, dias_sin_clasificar: int, trabajos_en_descanso: int, errores: array<int, array{colaborador_id: int, fecha: string, motivo: string}>}
     */
    public function ejecutar(Empresa $empresa, AsistenciaPeriodo $periodo): array
    {
        $faltantes = $this->detectarFaltantes($empresa, $periodo);
        $yaCubiertas = $this->contarCubiertasEnPeriodo($empresa, $periodo);

        $procesadas = 0;
        $errores = [];
        $resultadoIds = [];
        $porEstado = [];

        foreach (array_chunk($faltantes, self::TAMANO_CHUNK) as $lote) {
            foreach ($lote as $item) {
                [$colaborador, $fecha] = $item;
                try {
                    $resultado = $this->procesador->procesar($colaborador, Carbon::parse($fecha));
                    $procesadas++;
                    $resultadoIds[] = $resultado->id;
                    $porEstado[$resultado->estado] = ($porEstado[$resultado->estado] ?? 0) + 1;
                } catch (Throwable $e) {
                    $errores[] = ['colaborador_id' => $colaborador->id, 'fecha' => $fecha, 'motivo' => $e->getMessage()];
                }
            }
        }

        // Trabajo-en-descanso se genera junto al resultado (coexiste con su
        // estado, no lo reemplaza — AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO),
        // así que se cuenta aparte a partir de los IDs recién generados.
        $trabajosEnDescanso = $resultadoIds === [] ? 0 : AsistenciaIncidencia::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('resultado_diario_id', $resultadoIds)
            ->where('tipo', AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO)
            ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)
            ->count();

        return [
            'procesadas' => $procesadas,
            'ya_cubiertas' => $yaCubiertas,
            'faltas' => $porEstado['falta'] ?? 0,
            'dias_sin_clasificar' => $porEstado[AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR] ?? 0,
            'trabajos_en_descanso' => $trabajosEnDescanso,
            'errores' => $errores,
        ];
    }

    /**
     * Nunca fechas futuras: el límite superior siempre es min(fecha_fin,
     * hoy). Usa el timezone real configurado en la app (config('app.timezone'),
     * hoy UTC) — Agento no tiene ningún otro timezone configurado en
     * ninguna parte del código, así que "hoy" acá es exactamente el mismo
     * "hoy" que usa el resto del sistema, no una convención nueva.
     *
     * @return array<int, array{0: Colaborador, 1: string}>
     */
    private function detectarFaltantes(Empresa $empresa, AsistenciaPeriodo $periodo): array
    {
        [$fechaInicio, $fechaHastaProcesar] = $this->rangoAProcesar($periodo);
        if ($fechaHastaProcesar === null) {
            return [];
        }

        // Sin where('activo', true) a propósito — un colaborador cesado
        // sigue necesitando cobertura para las fechas ANTERIORES a su cese
        // (ver ColaboradorService::cesar / PlanificacionRotativaService,
        // que ya usa este mismo criterio granular por fecha, no por
        // colaborador completo). SoftDeletes ya excluye eliminados sin
        // necesidad de filtro extra (Colaborador::query() nunca los trae).
        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->whereDate('fecha_ingreso', '<=', $fechaHastaProcesar->toDateString())
            ->where(fn ($query) => $query->whereNull('fecha_cese')
                ->orWhereDate('fecha_cese', '>=', $fechaInicio->toDateString()))
            // Cada modelo se entrega después a ProcesarAsistenciaDiaria,
            // que necesita también empresa_id, horario_id, documento y las
            // condiciones de control de asistencia. Un select parcial deja
            // esos atributos en null y convierte toda la cobertura en error.
            ->get();

        if ($colaboradores->isEmpty()) {
            return [];
        }

        // Una sola consulta agregada para TODO el período, no una por
        // colaborador — evita el N+1 que volvería inviable esto con
        // cientos de colaboradores.
        $cubiertasPorColaborador = AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->whereDate('fecha', '>=', $fechaInicio->toDateString())
            ->whereDate('fecha', '<=', $fechaHastaProcesar->toDateString())
            ->get(['colaborador_id', 'fecha'])
            ->groupBy('colaborador_id')
            ->map(fn ($grupo) => $grupo->pluck('fecha')->map(fn ($fecha) => $fecha->toDateString())->flip());

        $faltantes = [];
        foreach ($colaboradores as $colaborador) {
            $inicioVigente = $colaborador->fecha_ingreso?->greaterThan($fechaInicio) ? $colaborador->fecha_ingreso : $fechaInicio;
            $finVigente = $colaborador->fecha_cese?->lessThan($fechaHastaProcesar) ? $colaborador->fecha_cese : $fechaHastaProcesar;
            $yaCubiertas = $cubiertasPorColaborador->get($colaborador->id);

            for ($fecha = $inicioVigente->copy(); $fecha->lte($finVigente); $fecha->addDay()) {
                $fechaTexto = $fecha->toDateString();
                if ($yaCubiertas === null || ! $yaCubiertas->has($fechaTexto)) {
                    $faltantes[] = [$colaborador, $fechaTexto];
                }
            }
        }

        return $faltantes;
    }

    private function contarCubiertasEnPeriodo(Empresa $empresa, AsistenciaPeriodo $periodo): int
    {
        [$fechaInicio, $fechaHastaProcesar] = $this->rangoAProcesar($periodo);

        if ($fechaHastaProcesar === null) {
            return 0;
        }

        return AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->whereDate('fecha', '>=', $fechaInicio->toDateString())
            ->whereDate('fecha', '<=', $fechaHastaProcesar->toDateString())
            ->count();
    }

    /** @return array{0: Carbon, 1: ?Carbon} */
    private function rangoAProcesar(AsistenciaPeriodo $periodo): array
    {
        $fechaInicio = $periodo->fecha_inicio->copy()->startOfDay();
        $hoy = $this->hoy();
        $fechaHastaProcesar = $periodo->fecha_fin->lessThan($hoy) ? $periodo->fecha_fin->copy() : $hoy;

        return [$fechaInicio, $fechaHastaProcesar->lt($fechaInicio) ? null : $fechaHastaProcesar];
    }
}
