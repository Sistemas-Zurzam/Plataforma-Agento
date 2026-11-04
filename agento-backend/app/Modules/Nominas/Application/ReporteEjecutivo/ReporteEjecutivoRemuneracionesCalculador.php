<?php

namespace App\Modules\Nominas\Application\ReporteEjecutivo;

use App\Modules\Nominas\Domain\Plame\ConceptosPlame;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use Illuminate\Support\Collection;

/**
 * Cálculo compartido del Reporte Ejecutivo de Remuneraciones — un desglose
 * de AFP/ONP y ESSALUD por colaborador, agrupado por empresa, con subtotal
 * por empresa y total general. Un único lugar para esta regla: tanto
 * ReporteEjecutivoRemuneracionesExcelExporter (hoja Detalle_Planilla) como
 * el endpoint JSON que alimenta la vista imprimible en PDF consumen esta
 * misma clase, para no duplicar el motor de cálculo en dos sitios que
 * puedan divergir.
 */
final class ReporteEjecutivoRemuneracionesCalculador
{
    /**
     * Mismo criterio que BoletaService::CODIGOS_PREVISIONALES para el aporte
     * obligatorio: AFP y ONP son mutuamente excluyentes por colaborador
     * (sistema_previsional), nunca coexisten en la misma boleta.
     */
    private const CODIGOS_APORTE_OBLIGATORIO = ['AFP_APORTE_OBLIGATORIO', 'ONP'];

    /** Aportación patronal de salud — ESSALUD y SIS son mutuamente excluyentes según Empresa.seguro_salud. */
    private const CODIGOS_APORTACION_SALUD = ['ESSALUD', 'SIS_APORTACION'];

    /** Ingreso base según motor: SUELDO_BASICO (planilla dependiente) u HONORARIO_BRUTO (recibos por honorarios). */
    private const CODIGOS_INGRESO_BASE = ['SUELDO_BASICO', 'HONORARIO_BRUTO'];

    private const CODIGOS_HORAS_EXTRA = ['HE_25', 'HE_35', 'HE_100'];

    /** Columnas monetarias sumables de una fila (misma clave que fila()), usadas para subtotales y total general. */
    public const COLUMNAS_MONTO = [
        'sueldo_bruto', 'bonos', 'base_afp', 'aporte_obligatorio', 'prima_seguro',
        'comision_afp', 'total_afp', 'otros_descuentos', 'reintegros', 'neto', 'essalud', 'costo_empresa',
    ];

    /**
     * @param  string  $periodo  Etiqueta legible del período (ej. "Julio 2026").
     * @param  Collection<int, Boleta>  $boletas  Con `colaborador`, `conceptos.concepto`
     *   y `empresa` ya precargados, y ya ordenadas por empresa y luego por colaborador.
     * @param  Collection<int, float>  $reintegrosPorBoleta  Ajuste neto de planillas
     *   complementarias aprobadas/pagadas del período, indexado por boleta_id — mismo
     *   criterio que ResumenContableService::complementariasPorEmpresa(), a nivel de
     *   colaborador en vez de empresa.
     * @return Collection<int, array<string, mixed>>
     */
    public static function filas(string $periodo, Collection $boletas, Collection $reintegrosPorBoleta = new Collection()): Collection
    {
        return $boletas->map(fn (Boleta $boleta) => self::fila($periodo, $boleta, (float) $reintegrosPorBoleta->get($boleta->id, 0.0)))->values();
    }

    /**
     * Agrupa filas() por empresa, con el subtotal de cada grupo ya calculado.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<string, array{filas: Collection<int, array<string, mixed>>, subtotal: array<string, float>, colaboradores: int}> indexada por nombre de empresa
     */
    public static function agruparPorEmpresa(Collection $filas): Collection
    {
        return $filas->groupBy('empresa')->map(fn (Collection $filasEmpresa) => [
            'filas' => $filasEmpresa->values(),
            'colaboradores' => $filasEmpresa->count(),
            'subtotal' => self::sumarColumnas($filasEmpresa),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, float>
     */
    public static function totalGeneral(Collection $filas): array
    {
        return self::sumarColumnas($filas);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, float>
     */
    private static function sumarColumnas(Collection $filas): array
    {
        $totales = [];
        foreach (self::COLUMNAS_MONTO as $clave) {
            $totales[$clave] = round((float) $filas->sum($clave), 2);
        }

        return $totales;
    }

    /**
     * @return array{empresa: string, periodo: string, dni: string, nombre: string, tipo: string,
     *   sueldo_bruto: float, bonos: float, base_afp: float, aporte_obligatorio: float, prima_seguro: float,
     *   comision_afp: float, total_afp: float, otros_descuentos: float, reintegros: float, neto: float,
     *   essalud: float, costo_empresa: float, estado: string}
     */
    private static function fila(string $periodo, Boleta $boleta, float $reintegros = 0.0): array
    {
        $colaborador = $boleta->colaborador;
        $conceptos = $boleta->conceptos;

        $totalIngresos = round((float) $boleta->total_ingresos, 2);
        $sueldoBruto = self::sumarConceptos($conceptos, self::CODIGOS_INGRESO_BASE);
        $aporteObligatorio = self::sumarConceptos($conceptos, self::CODIGOS_APORTE_OBLIGATORIO);
        $primaSeguro = self::sumarConceptos($conceptos, ['AFP_PRIMA_SEGURO']);
        $comisionAfp = self::sumarConceptos($conceptos, ['AFP_COMISION']);
        $baseAfp = round((float) ($conceptos->first(
            fn (BoletaConcepto $c) => in_array($c->concepto?->codigo, self::CODIGOS_APORTE_OBLIGATORIO, true)
        )?->base_utilizada ?? 0), 2);
        $totalAfp = round($aporteObligatorio + $primaSeguro + $comisionAfp, 2);
        $essalud = self::sumarConceptos($conceptos, self::CODIGOS_APORTACION_SALUD);

        return [
            'boleta_id' => $boleta->id,
            'empresa' => $boleta->empresa?->nombre_comercial ?? 'Sin empresa',
            'periodo' => $periodo,
            'dni' => (string) $colaborador?->numero_documento,
            'nombre' => trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')),
            'area' => $colaborador?->area?->nombre ?? '',
            'cargo' => (string) ($colaborador?->cargo ?? ''),
            'tipo' => $boleta->regimen_laboral_snapshot === 'Locacion de Servicios' ? 'RH' : 'Planilla',
            'sueldo_bruto' => $sueldoBruto,
            'bonos' => round($totalIngresos - $sueldoBruto, 2),
            'base_afp' => $baseAfp,
            'aporte_obligatorio' => $aporteObligatorio,
            'prima_seguro' => $primaSeguro,
            'comision_afp' => $comisionAfp,
            'total_afp' => $totalAfp,
            // Todo egreso que no es AFP/ONP: tardanzas, faltas, adelantos,
            // renta de 5ta, descuentos operativos, etc., en una sola columna.
            'otros_descuentos' => round((float) $boleta->total_egresos - $totalAfp, 2),
            // Ajuste de planillas complementarias — la boleta original nunca
            // se modifica (ver PlanillaComplementariaService), así que el
            // neto "real" de este período es el de la boleta más este
            // ajuste, mismo criterio que ResumenContableService::consolidar().
            'reintegros' => round($reintegros, 2),
            'neto' => round((float) $boleta->neto_a_pagar + $reintegros, 2),
            'essalud' => $essalud,
            'costo_empresa' => round($totalIngresos + $essalud, 2),
            'estado' => $boleta->estado === 'pagada' ? 'Pagado' : ucfirst((string) $boleta->estado),
        ];
    }

    /**
     * Planilla pagada a detalle: una columna por concepto efectivamente
     * pagado (ver ConceptosEfectivosPorBoleta — los reintegros ya vienen
     * repartidos en sus conceptos). Bonos y HE van separados por concepto
     * con su total; los descuentos que no son AFP/ONP se segmentan según el
     * concepto registrado (adelanto, error operativo, falta, …). Solo se
     * incluyen las columnas dinámicas con algún monto en el reporte.
     *
     * @param  Collection<int, array<string, mixed>>  $filas  Resultado de filas().
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $conceptosPorBoleta  ConceptosEfectivosPorBoleta::resolver().
     * @return array{columnas: list<array{clave: string, titulo: string, grupo: string, total: bool}>,
     *   filas: Collection<int, array<string, mixed>>}
     */
    public static function detalle(Collection $filas, Collection $conceptosPorBoleta): array
    {
        $dinamicas = ['ingreso' => [], 'bono' => [], 'descuento' => []];

        $filasDetalle = $filas->map(function (array $fila) use ($conceptosPorBoleta, &$dinamicas) {
            $montos = array_fill_keys([
                'sueldo_basico', 'HE_25', 'HE_35', 'HE_100', 'aporte_obligatorio', 'prima_seguro', 'comision_afp', 'essalud',
            ], 0.0);
            $totalIngresos = 0.0;
            $totalDescuentos = 0.0;

            foreach ($conceptosPorBoleta->get($fila['boleta_id'], collect()) as $linea) {
                $codigo = $linea['codigo'];
                $monto = (float) $linea['monto'];

                if ($linea['tipo'] === 'aportacion') {
                    if (in_array($codigo, self::CODIGOS_APORTACION_SALUD, true)) {
                        $montos['essalud'] += $monto;
                    }

                    continue;
                }

                if ($linea['tipo'] === 'ingreso') {
                    $totalIngresos += $monto;
                    $clave = match (true) {
                        in_array($codigo, self::CODIGOS_INGRESO_BASE, true) => 'sueldo_basico',
                        in_array($codigo, self::CODIGOS_HORAS_EXTRA, true) => $codigo,
                        default => null,
                    };
                    $grupo = self::esBono($codigo) ? 'bono' : 'ingreso';
                } else {
                    $totalDescuentos += $monto;
                    $clave = match (true) {
                        in_array($codigo, self::CODIGOS_APORTE_OBLIGATORIO, true) => 'aporte_obligatorio',
                        $codigo === 'AFP_PRIMA_SEGURO' => 'prima_seguro',
                        $codigo === 'AFP_COMISION' => 'comision_afp',
                        default => null,
                    };
                    $grupo = 'descuento';
                }

                if ($clave === null) {
                    // Bono de productividad, de puntualidad, adelanto, error
                    // operativo…: la definición concreta manda sobre el
                    // concepto genérico (BONIFICACION, etc.).
                    $clave = $linea['definicion_id'] ? "def:{$linea['definicion_id']}" : "cod:{$codigo}";
                    $dinamicas[$grupo][$clave] = $linea['definicion'] ?: $linea['nombre'];
                }
                $montos[$clave] = ($montos[$clave] ?? 0.0) + $monto;
            }

            return [...$fila, 'montos' => array_map(fn ($v) => round($v, 2), [
                ...$montos,
                'total_ingresos' => $totalIngresos,
                'total_descuentos' => $totalDescuentos,
                'neto' => $totalIngresos - $totalDescuentos,
                'costo_empresa' => $totalIngresos + $montos['essalud'],
            ])];
        })->values();

        $ordenar = function (array $columnas): array {
            asort($columnas, SORT_NATURAL | SORT_FLAG_CASE);

            return $columnas;
        };
        $columnas = [['clave' => 'sueldo_basico', 'titulo' => 'Sueldo básico', 'grupo' => 'Ingresos', 'total' => false]];
        foreach ($ordenar($dinamicas['ingreso']) as $clave => $titulo) {
            $columnas[] = ['clave' => $clave, 'titulo' => $titulo, 'grupo' => 'Ingresos', 'total' => false];
        }
        foreach ($ordenar($dinamicas['bono']) as $clave => $titulo) {
            $columnas[] = ['clave' => $clave, 'titulo' => $titulo, 'grupo' => 'Bonos', 'total' => false];
        }
        $columnas[] = ['clave' => 'total_bonos', 'titulo' => 'Total bonos', 'grupo' => 'Bonos', 'total' => true];
        $columnas[] = ['clave' => 'HE_25', 'titulo' => 'HE 25%', 'grupo' => 'Horas extra', 'total' => false];
        $columnas[] = ['clave' => 'HE_35', 'titulo' => 'HE 35%', 'grupo' => 'Horas extra', 'total' => false];
        $columnas[] = ['clave' => 'HE_100', 'titulo' => 'HE 100%', 'grupo' => 'Horas extra', 'total' => false];
        $columnas[] = ['clave' => 'total_he', 'titulo' => 'Total horas extra', 'grupo' => 'Horas extra', 'total' => true];
        $columnas[] = ['clave' => 'total_ingresos', 'titulo' => 'Total ingresos', 'grupo' => 'Ingresos', 'total' => true];
        $columnas[] = ['clave' => 'aporte_obligatorio', 'titulo' => 'Aporte AFP / ONP', 'grupo' => 'AFP / ONP', 'total' => false];
        $columnas[] = ['clave' => 'prima_seguro', 'titulo' => 'Prima seguro', 'grupo' => 'AFP / ONP', 'total' => false];
        $columnas[] = ['clave' => 'comision_afp', 'titulo' => 'Comisión AFP', 'grupo' => 'AFP / ONP', 'total' => false];
        $columnas[] = ['clave' => 'total_afp', 'titulo' => 'Total AFP / ONP', 'grupo' => 'AFP / ONP', 'total' => true];
        foreach ($ordenar($dinamicas['descuento']) as $clave => $titulo) {
            $columnas[] = ['clave' => $clave, 'titulo' => $titulo, 'grupo' => 'Otros descuentos', 'total' => false];
        }
        $columnas[] = ['clave' => 'total_otros_descuentos', 'titulo' => 'Total otros descuentos', 'grupo' => 'Otros descuentos', 'total' => true];
        $columnas[] = ['clave' => 'total_descuentos', 'titulo' => 'Total descuentos', 'grupo' => 'Resultado', 'total' => true];
        $columnas[] = ['clave' => 'neto', 'titulo' => 'Neto a pagar', 'grupo' => 'Resultado', 'total' => true];
        $columnas[] = ['clave' => 'essalud', 'titulo' => 'ESSALUD', 'grupo' => 'Empleador', 'total' => false];
        $columnas[] = ['clave' => 'costo_empresa', 'titulo' => 'Costo empresa', 'grupo' => 'Empleador', 'total' => true];

        $clavesBono = array_keys($dinamicas['bono']);
        $clavesDescuento = array_keys($dinamicas['descuento']);
        $filasDetalle = $filasDetalle->map(function (array $fila) use ($columnas, $clavesBono, $clavesDescuento) {
            $m = $fila['montos'];
            $suma = fn (array $claves) => round(array_sum(array_map(fn ($c) => $m[$c] ?? 0.0, $claves)), 2);
            $m['total_bonos'] = $suma($clavesBono);
            $m['total_he'] = $suma(self::CODIGOS_HORAS_EXTRA);
            $m['total_afp'] = $suma(['aporte_obligatorio', 'prima_seguro', 'comision_afp']);
            $m['total_otros_descuentos'] = $suma($clavesDescuento);
            $fila['montos'] = array_map(fn (array $col) => $m[$col['clave']] ?? 0.0, array_column($columnas, null, 'clave'));

            return $fila;
        });

        return ['columnas' => $columnas, 'filas' => $filasDetalle];
    }

    /**
     * Suma por columna de filas de detalle() — subtotal por empresa y total general.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  list<string>  $claves
     * @return array<string, float>
     */
    public static function sumarMontos(Collection $filas, array $claves): array
    {
        $totales = [];
        foreach ($claves as $clave) {
            $totales[$clave] = round((float) $filas->sum(fn (array $fila) => $fila['montos'][$clave]), 2);
        }

        return $totales;
    }

    /** Bonos: los conceptos con definición concreta (Bono de productividad, de puntualidad…) y cualquier BONO_*. */
    private static function esBono(string $codigo): bool
    {
        return in_array($codigo, ConceptosPlame::CON_DEFINICION, true) || str_starts_with($codigo, 'BONO_');
    }

    /** @param  Collection<int, BoletaConcepto>  $conceptos */
    private static function sumarConceptos(Collection $conceptos, array $codigos): float
    {
        return round((float) $conceptos
            ->filter(fn (BoletaConcepto $c) => in_array($c->concepto?->codigo, $codigos, true))
            ->sum('monto'), 2);
    }
}
