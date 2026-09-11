<?php

namespace App\Modules\Nominas\Domain;

/**
 * Matriz explícita de clasificación de una fila de "Data Planilla"/"Data
 * Provisiones" (Incremento 2 — ver el plan aprobado en
 * DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md). Clase pura: sin Eloquent, sin
 * infraestructura — depende de varios datos combinados con reglas
 * condicionales que pueden cambiar por motivos legales/contables, nunca por
 * el motor de persistencia (criterio de Domain de CLAUDE.md).
 *
 * Fundamental: esta clase NUNCA produce `clasificacion = 'aplicable'`. Tres
 * rondas de revisión con el propietario concluyeron que ni "ESTADO NETO =
 * Cancelado" ni "COD. DEPOSITO" son evidencia suficiente de un pago/depósito
 * real — la única forma de llegar a `aplicable` es una confirmación humana
 * explícita con evidencia (`confirmarCtsDepositada()`,
 * `confirmarGratificacionPagada()`, ambas en
 * `ImportarAntecedentesHistoricosService`). Esta clase solo distingue
 * `no_aplicable` (planilla ordinaria, liquidación histórica, locador) de
 * `observado` (todo lo demás: pendiente de pago, CTS, gratificación,
 * desconocido).
 *
 * El pre-filtro de empresa (regla 0 del plan) NO vive aquí — decide si la
 * fila se persiste siquiera, y eso es responsabilidad del lector/servicio,
 * no de esta clasificación de negocio.
 */
class ClasificadorAntecedenteHistorico
{
    /**
     * Conceptos de planilla ordinaria confirmados contra los datos reales
     * de "Data Planilla" (34 valores distintos observados) — nunca son un
     * antecedente histórico, sea cual sea su estado.
     */
    private const CONCEPTOS_PLANILLA_ORDINARIA = [
        'BASICO', 'AFP FONDO', 'AFP SEGURO', 'AFP COMISION', 'TARDANZA', 'ESSALUD',
        'SEG. INTEG. SALUD.', 'COMEDOR', 'HORAS EXTRAS 25%', 'HORAS EXTRAS 35%', 'HORAS EXTRAS 100%',
        'ADELANTO SUELDO', 'ADELANTO GRATIFICACION', 'ADELANTO DE BONO', 'ADELANTO QUINCENA',
        'DSCTO DE PROCESOS', 'COMPRA MERCADERIA', 'BONO DE PRODUCTIVIDAD', 'COMISIONES',
        'REINTEGROS', 'ONP', 'PRIMERO DE MAYO', 'DESCANSO MEDICO', 'NETO', 'INCENTIVOS',
        'BONIF. EXTRAORDINARIA', 'PROD. COST.',
    ];

    /**
     * Conceptos que, bajo régimen de locador (Recib. Hon.), nunca aplican —
     * un locador no tiene gratificación, CTS ni vacaciones (misma regla que
     * ya aplica `LiquidacionCeseService`).
     */
    private const CONCEPTOS_EXCLUSIVOS_DEPENDIENTE = [
        'GRATIFICACION ORDINARIA', 'GRATIFICACION TRUNCA', 'CTS', 'VACACIONES', 'VACACIONES TRUNCAS',
        'BONIF. EXTRAORDINARIA',
    ];

    /**
     * @param  string  $tipoCalculo  Columna "TIPO DE CALCULO" (PLANILLA|LIQUIDACION|CTS, u otro).
     * @param  string  $concepto  Columna "CONCEPTO", ya en mayúsculas y sin espacios sobrantes.
     * @param  string  $estadoNeto  Columna "ESTADO NETO" (Cancelado|Pendiente Pago).
     * @param  bool  $esLocador  `PLANILLA = "Recib. Hon."` o `colaborador->regimen_laboral === 'Locacion de Servicios'`.
     * @return array{clasificacion: string, tipo_antecedente: ?string, advertencias: array<int, string>}
     */
    public function clasificar(string $tipoCalculo, string $concepto, string $estadoNeto, bool $esLocador): array
    {
        $tipoCalculo = mb_strtoupper(trim($tipoCalculo));
        $concepto = mb_strtoupper(trim($concepto));
        $estadoNeto = trim($estadoNeto);

        if (str_starts_with($tipoCalculo, 'PROV')) {
            return $this->resultado('no_aplicable', null, [
                'Provisión mensual — nunca es un pago ni un depósito, no se aplica bajo ninguna circunstancia.',
            ]);
        }

        if ($esLocador && in_array($concepto, self::CONCEPTOS_EXCLUSIVOS_DEPENDIENTE, true)) {
            return $this->resultado('no_aplicable', null, [
                'Un locador (Recibos por Honorarios) no tiene gratificación, CTS ni vacaciones — fila descartada.',
            ]);
        }

        if ($tipoCalculo === 'LIQUIDACION') {
            return $this->resultado('no_aplicable', 'liquidacion_historica', [
                'Beneficio de una liquidación de cese ya pagada. Si el colaborador fue recontratado, pertenece a un '
                .'vínculo laboral anterior — no se aplica al vínculo actual en este incremento.',
            ]);
        }

        if ($estadoNeto !== 'Cancelado') {
            return $this->resultado('observado', null, [
                "ESTADO NETO = \"{$estadoNeto}\" — solo un estado \"Cancelado\" puede eventualmente confirmarse como pagado; nunca se aplica automáticamente.",
            ]);
        }

        if ($tipoCalculo === 'CTS' && $concepto === 'CTS') {
            return $this->resultado('observado', 'cts_depositada', [
                '"Cancelado" no es evidencia suficiente de un depósito bancario real. Requiere confirmación explícita '
                .'mediante confirmarCtsDepositada() con referencia y fecha de depósito.',
            ]);
        }

        if ($tipoCalculo === 'PLANILLA' && $concepto === 'GRATIFICACION ORDINARIA') {
            return $this->resultado('observado', 'gratificacion_pagada', [
                'El Excel no trae una fecha de pago efectiva verificable (mes/año/estado/importe no bastan). Requiere '
                .'confirmación explícita mediante confirmarGratificacionPagada() con fecha de pago.',
            ]);
        }

        if (in_array($concepto, self::CONCEPTOS_PLANILLA_ORDINARIA, true)) {
            return $this->resultado('no_aplicable', null, []);
        }

        return $this->resultado('observado', 'otro', [
            "Concepto \"{$concepto}\" (tipo de cálculo \"{$tipoCalculo}\") no está en la matriz de clasificación conocida — requiere revisión humana.",
        ]);
    }

    /**
     * @param  array<int, string>  $advertencias
     * @return array{clasificacion: string, tipo_antecedente: ?string, advertencias: array<int, string>}
     */
    private function resultado(string $clasificacion, ?string $tipoAntecedente, array $advertencias): array
    {
        return ['clasificacion' => $clasificacion, 'tipo_antecedente' => $tipoAntecedente, 'advertencias' => $advertencias];
    }
}
