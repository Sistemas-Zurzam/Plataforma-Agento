<?php

namespace App\Modules\Nominas\Application\ReporteEjecutivo;

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

    /** Columnas monetarias sumables de una fila (misma clave que fila()), usadas para subtotales y total general. */
    public const COLUMNAS_MONTO = [
        'sueldo_bruto', 'bonos', 'base_afp', 'aporte_obligatorio', 'prima_seguro',
        'comision_afp', 'total_afp', 'otros_descuentos', 'neto', 'essalud', 'costo_empresa',
    ];

    /**
     * @param  string  $periodo  Etiqueta legible del período (ej. "Julio 2026").
     * @param  Collection<int, Boleta>  $boletas  Con `colaborador`, `conceptos.concepto`
     *   y `empresa` ya precargados, y ya ordenadas por empresa y luego por colaborador.
     * @return Collection<int, array<string, mixed>>
     */
    public static function filas(string $periodo, Collection $boletas): Collection
    {
        return $boletas->map(fn (Boleta $boleta) => self::fila($periodo, $boleta))->values();
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
     *   comision_afp: float, total_afp: float, otros_descuentos: float, neto: float, essalud: float,
     *   costo_empresa: float, estado: string}
     */
    private static function fila(string $periodo, Boleta $boleta): array
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
            'empresa' => $boleta->empresa?->nombre_comercial ?? 'Sin empresa',
            'periodo' => $periodo,
            'dni' => (string) $colaborador?->numero_documento,
            'nombre' => trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')),
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
            'neto' => round((float) $boleta->neto_a_pagar, 2),
            'essalud' => $essalud,
            'costo_empresa' => round($totalIngresos + $essalud, 2),
            'estado' => $boleta->estado === 'pagada' ? 'Pagado' : ucfirst((string) $boleta->estado),
        ];
    }

    /** @param  Collection<int, BoletaConcepto>  $conceptos */
    private static function sumarConceptos(Collection $conceptos, array $codigos): float
    {
        return round((float) $conceptos
            ->filter(fn (BoletaConcepto $c) => in_array($c->concepto?->codigo, $codigos, true))
            ->sum('monto'), 2);
    }
}
