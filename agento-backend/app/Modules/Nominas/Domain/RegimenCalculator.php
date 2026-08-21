<?php

namespace App\Modules\Nominas\Domain;

use App\Modules\Personas\Models\Colaborador;

/**
 * Estrategia de cálculo por régimen laboral (Sección 20 del encargo). Un
 * concepto de línea se representa como:
 * ['codigo', 'monto', 'base_utilizada', 'tasa_aplicada', 'cantidad', 'formula_texto']
 * — codigo referencia ConceptoRemuneracion::codigo (catálogo único de
 * verdad); el resto son datos REALES del cálculo para la fórmula
 * desplegable de la UI (nunca un texto estático).
 */
interface RegimenCalculator
{
    /** @return array{dias_pagados: float, linea: array} */
    public function calcularBasico(float $sueldoBasico, float $diasFalta, float $horasPermisoSinGoce): array;

    /** @return array<int, array> */
    public function calcularHorasExtra(float $sueldoBasico, float $horas25, float $horas35, float $horas100, array $parametros): array;

    public function calcularAsignacionFamiliar(bool $calificaPorHijos, array $parametros): ?array;

    /**
     * @param  array<int, array{minutos_desde: int, minutos_hasta: ?int, tipo: string, valor: ?float}>  $reglas
     *   Reglas escalonadas configuradas por la empresa (vacío = usa la
     *   fórmula plana valor_minuto × minutos, comportamiento de siempre).
     */
    public function calcularDescuentoTardanza(float $sueldoBasico, int $minutosTardanza, array $reglas = []): array;

    /** @return array<int, array> */
    public function calcularAporteAfpOnp(Colaborador $colaborador, float $baseRemunerativa, array $parametros, string $fechaCorte): array;

    /**
     * @param  string  $seguroSalud  'essalud' | 'sis' — de Empresa::seguro_salud.
     *   SIS es un monto fijo mensual, no un % del sueldo, así que no tiene
     *   piso legal ni depende de la base remunerativa.
     * @return array{linea: array, piso_activado: bool}
     */
    public function calcularEsSalud(float $baseRemunerativa, array $parametros, string $seguroSalud = 'essalud'): array;

    /** @return array<int, array> */
    public function calcularProvisiones(float $baseRemunerativaRegular, float $gratificacionesPercibidasSemestre, array $parametros): array;
}
