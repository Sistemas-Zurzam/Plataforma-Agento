<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clave', 'nombre'])]
class Afp extends Model
{
    public function comisiones(): HasMany
    {
        return $this->hasMany(ComisionAfp::class);
    }
}
