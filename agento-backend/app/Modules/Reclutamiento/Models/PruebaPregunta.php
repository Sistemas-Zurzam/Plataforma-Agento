<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['prueba_id', 'dimension_id', 'tipo', 'invertida', 'enunciado', 'imagen_id', 'orden', 'peso'])]
class PruebaPregunta extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_preguntas';

    protected function casts(): array
    {
        return ['peso' => 'integer', 'orden' => 'integer', 'invertida' => 'boolean'];
    }

    /** Afirmación que se responde con la escala de la prueba. */
    public const ESCALA = 'escala';

    /** Pregunta con opciones propias (texto y/o imagen) y su valor. */
    public const OPCIONES = 'opciones';

    public const TIPOS = [self::ESCALA, self::OPCIONES];

    public function imagen(): BelongsTo
    {
        return $this->belongsTo(PruebaImagen::class, 'imagen_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(PruebaOpcion::class, 'pregunta_id')->orderBy('orden')->orderBy('id');
    }
}
