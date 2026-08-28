<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Equivalencia "valor interno fijo de Agento" → "código oficial AFPnet".
 * Tabla propia, sin relación con sunat_mapeos (PLAME) — ver migración
 * 2026_08_27_000086_crear_afpnet_mapeos.
 */
#[Fillable(['tipo', 'clave_interna', 'codigo_afpnet', 'activo'])]
class AfpNetMapeo extends Model
{
    protected $table = 'afpnet_mapeos';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
