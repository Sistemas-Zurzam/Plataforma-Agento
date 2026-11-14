<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de puesto sugerido por la guía de la prueba con las dimensiones que
 * conviene priorizar (ej. "Atención al cliente" → SER, EST, COM, RES).
 */
#[Fillable(['prueba_id', 'nombre', 'orden'])]
class PruebaPerfilPuesto extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_perfiles_puesto';

    public function dimensiones(): HasMany
    {
        return $this->hasMany(PruebaPerfilDimension::class, 'perfil_id');
    }
}
