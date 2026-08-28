<?php

namespace App\Modules\Nominas\Domain\Plame;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;

/**
 * Datos YA cargados de un ciclo, listos para que los Generators los lean
 * (Sección 71) — evita que PlameExportService pase parámetros sueltos a
 * cada Generator y evita que cada uno repita sus propias queries (Sección
 * 68). Solo datos, sin lógica: cada Generator decide qué campos usar.
 *
 * Deliberadamente NO incluye el historial contractual
 * (ColaboradorCondicionLaboral) que sí usa PlameValidator: régimen laboral
 * y categoría de trabajador pertenecen a estructuras de T-Registro (E5),
 * fuera del alcance de E7/E14/E15/E18/E20 — ningún Generator las necesita,
 * cargarlas acá sería una query sin ningún consumidor real (Sección 69).
 */
final class PlameExportContext
{
    /**
     * @param  Collection<int, \App\Modules\Nominas\Models\Boleta>  $boletasPlanilla  Vigentes, régimen dependiente (excluye Locación de Servicios).
     * @param  Collection<int, \App\Modules\Nominas\Models\Boleta>  $boletasRh  Vigentes, régimen Locación de Servicios.
     */
    public function __construct(
        public readonly Empresa $empresa,
        public readonly CicloRemunerativo $ciclo,
        public readonly Collection $boletasPlanilla,
        public readonly Collection $boletasRh,
        public readonly SunatMapeoLookup $mapeos,
    ) {}
}
