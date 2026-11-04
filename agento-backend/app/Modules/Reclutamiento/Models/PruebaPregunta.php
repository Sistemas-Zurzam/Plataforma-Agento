<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['prueba_id', 'dimension_id', 'enunciado', 'orden', 'peso'])]
class PruebaPregunta extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_preguntas';

    protected function casts(): array
    {
        return ['peso' => 'integer', 'orden' => 'integer'];
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(PruebaOpcion::class, 'pregunta_id')->orderBy('orden')->orderBy('id');
    }
}
