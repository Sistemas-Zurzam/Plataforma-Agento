<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Imagen de una pregunta u opción de prueba. Archivo en disco privado; se
 * muestra con una URL firmada que vence (sirve en <img> sin token JWT y no
 * deja el archivo expuesto de forma permanente).
 */
#[Hidden(['disco', 'ruta'])]
#[Fillable(['empresa_id', 'disco', 'ruta', 'mime', 'tamano', 'subido_por'])]
class PruebaImagen extends Model
{
    public const MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const HORAS_VIGENCIA_URL = 8;

    protected $table = 'seleccion_prueba_imagenes';

    public function url(): string
    {
        return URL::temporarySignedRoute(
            'seleccion.pruebas.imagen',
            now()->addHours(self::HORAS_VIGENCIA_URL),
            ['imagen' => $this->id],
            absolute: false,
        );
    }

    /**
     * @return array{id: int, url: string}
     */
    public function paraRespuesta(): array
    {
        return ['id' => $this->id, 'url' => $this->url()];
    }
}
