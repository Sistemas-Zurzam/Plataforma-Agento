<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clave', 'grupo', 'nombre', 'unidad', 'orden'])]
class ParametroLaboralDefinicion extends Model
{
    /**
     * Eloquent pluraliza con reglas en inglés: "definicion" -> "definicions"
     * (incorrecto en español, la tabla es "definiciones"). Se fija el
     * nombre explícitamente.
     */
    protected $table = 'parametro_laboral_definiciones';

    public function valores(): HasMany
    {
        return $this->hasMany(ParametroLaboralValor::class, 'definicion_id');
    }
}
