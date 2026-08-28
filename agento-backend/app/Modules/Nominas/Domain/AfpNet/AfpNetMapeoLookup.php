<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use App\Modules\Nominas\Models\AfpNetMapeo;
use Illuminate\Support\Collection;

/**
 * Envoltorio de solo lectura sobre afpnet_mapeos, precargado una sola vez
 * (nunca una query por colaborador). A diferencia de
 * SunatMapeoLookup (PLAME), NO lanza excepción por mapeo faltante — acá
 * solo se está construyendo el Validator, no un exportador todavía; quién
 * decide si un mapeo faltante bloquea o no es AfpNetValidator (documento
 * siempre bloquea, AFP vacía no bloquea, AFP informada sin mapeo sí).
 */
final class AfpNetMapeoLookup
{
    /** @var Collection<string, Collection<string, AfpNetMapeo>> */
    private readonly Collection $porTipo;

    private function __construct(Collection $porTipo)
    {
        $this->porTipo = $porTipo;
    }

    public static function cargar(): self
    {
        return new self(
            AfpNetMapeo::where('activo', true)->get()
                ->groupBy('tipo')
                ->map(fn (Collection $g) => $g->keyBy('clave_interna')),
        );
    }

    public function codigoDocumento(string $tipoDocumento): ?string
    {
        return $this->porTipo->get('tipo_documento')?->get($tipoDocumento)?->codigo_afpnet;
    }

    public function codigoAfp(?string $claveAfp): ?string
    {
        if ($claveAfp === null) {
            return null;
        }

        return $this->porTipo->get('afp')?->get($claveAfp)?->codigo_afpnet;
    }

    public function tieneMapeoAfp(string $claveAfp): bool
    {
        return $this->porTipo->get('afp')?->has($claveAfp) ?? false;
    }
}
