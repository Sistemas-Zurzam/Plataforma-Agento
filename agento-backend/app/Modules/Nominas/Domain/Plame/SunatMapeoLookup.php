<?php

namespace App\Modules\Nominas\Domain\Plame;

use App\Modules\Nominas\Models\SunatMapeo;
use App\Modules\Nominas\Services\SunatCatalogoService;
use Illuminate\Support\Collection;

/**
 * Envoltorio de solo lectura sobre los mapeos SUNAT ya precargados (Sección
 * 68: una sola query para toda la exportación, nunca una por línea) — los
 * Generators nunca hacen `if dni => 01` a mano (Sección 11/36), siempre
 * consumen esto. Mismo criterio de "configurado" que
 * PlameValidator::mapeoConfigurado() — reutiliza
 * SunatCatalogoService::calcularEstado(), nunca lo reimplementa.
 */
final class SunatMapeoLookup
{
    private const TIPOS = ['tipo_documento', 'tipo_trabajador', 'regimen_laboral', 'tipo_comprobante_rh'];

    /** @var Collection<string, Collection<string, SunatMapeo>> */
    private readonly Collection $porTipo;

    private function __construct(Collection $porTipo)
    {
        $this->porTipo = $porTipo;
    }

    public static function cargar(): self
    {
        return new self(
            SunatMapeo::whereIn('tipo', self::TIPOS)->get()
                ->groupBy('tipo')
                ->map(fn (Collection $g) => $g->keyBy('clave_interna')),
        );
    }

    public function codigo(string $tipo, string $claveInterna): string
    {
        $mapeo = $this->porTipo->get($tipo)?->get($claveInterna);

        if (! $this->estaConfigurado($mapeo)) {
            throw PlameExportException::mapeoSunatFaltante($tipo, $claveInterna);
        }

        return $mapeo->codigo_sunat;
    }

    public function codigoComprobante(string $codigoSunat): string
    {
        $mapeo = $this->porTipo->get('tipo_comprobante_rh')?->first(fn (SunatMapeo $m) => $m->codigo_sunat === $codigoSunat);

        if (! $this->estaConfigurado($mapeo)) {
            throw PlameExportException::mapeoSunatFaltante('tipo_comprobante_rh', $codigoSunat);
        }

        return $mapeo->codigo_sunat;
    }

    private function estaConfigurado(?SunatMapeo $mapeo): bool
    {
        if (! $mapeo) {
            return false;
        }

        return SunatCatalogoService::calcularEstado(! $mapeo->activo, filled($mapeo->codigo_sunat), $mapeo->bloqueado_por_modelo) === 'configurado';
    }
}
