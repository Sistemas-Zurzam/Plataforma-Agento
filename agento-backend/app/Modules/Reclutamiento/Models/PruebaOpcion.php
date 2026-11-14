<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "valor" es la clave de calificación: oculta por defecto en serialización.
 */
#[Hidden(['valor'])]
#[Fillable(['pregunta_id', 'texto', 'imagen_id', 'valor', 'orden'])]
class PruebaOpcion extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_opciones';

    public function imagen(): BelongsTo
    {
        return $this->belongsTo(PruebaImagen::class, 'imagen_id');
    }

    protected function casts(): array
    {
        return ['valor' => 'float'];
    }
}
