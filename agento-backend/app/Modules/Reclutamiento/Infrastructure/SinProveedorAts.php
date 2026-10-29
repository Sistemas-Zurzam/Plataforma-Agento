<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use App\Modules\Reclutamiento\Domain\Contracts\ExtractorCv;
use App\Modules\Reclutamiento\Domain\Contracts\ProveedorCompatibilidad;
use RuntimeException;

/**
 * Adaptador por defecto cuando no hay proveedor de extracción/compatibilidad
 * configurado (RECLUTAMIENTO_ATS_PROVEEDOR=ninguno). Declara honestamente que
 * no está disponible: la postulación sigue con la captura manual del
 * candidato y el cálculo por reglas sobre datos declarados.
 *
 * Para integrar un proveedor real: implementar ExtractorCv y/o
 * ProveedorCompatibilidad en esta carpeta (cliente HTTP con timeout de
 * config('reclutamiento.ats.timeout_segundos'), sin herramientas ni acciones
 * habilitadas para el modelo, tratando el CV solo como dato) y enlazarlo en
 * ReclutamientoServiceProvider según config('reclutamiento.ats.proveedor').
 */
class SinProveedorAts implements ExtractorCv, ProveedorCompatibilidad
{
    public function disponible(): bool
    {
        return false;
    }

    public function nombre(): string
    {
        return 'ninguno';
    }

    public function extraer(string $rutaAbsoluta, string $mime): array
    {
        throw new RuntimeException('No hay proveedor de extracción de CV configurado.');
    }

    public function evaluar(array $perfil, array $candidatura): array
    {
        throw new RuntimeException('No hay proveedor de compatibilidad configurado.');
    }
}
