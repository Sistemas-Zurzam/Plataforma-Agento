<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Reclutamiento\Models\Scopes\PruebaVisibleScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([PruebaVisibleScope::class])]
#[Fillable([
    'empresa_id', 'familia', 'version', 'nombre', 'descripcion', 'tipo', 'area_conocimiento', 'tiempo_limite_minutos', 'estado',
    'validada', 'validacion_fuente', 'validada_por', 'validada_at', 'creado_por',
])]
class Prueba extends Model
{
    public const TIPOS = ['psicometrica', 'conocimientos'];

    public const BORRADOR = 'borrador';

    public const PUBLICADA = 'publicada';

    public const ARCHIVADA = 'archivada';

    protected $table = 'seleccion_pruebas';

    protected function casts(): array
    {
        return ['validada' => 'boolean', 'validada_at' => 'datetime', 'version' => 'integer', 'tiempo_limite_minutos' => 'integer'];
    }

    /**
     * Prueba del banco único de Agento (disponible para todas las empresas).
     */
    public function esDeAgento(): bool
    {
        return $this->empresa_id === null;
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(PruebaAsignacion::class, 'prueba_id');
    }

    public function escala(): HasMany
    {
        return $this->hasMany(PruebaEscala::class, 'prueba_id')->orderBy('orden')->orderBy('id');
    }

    public function perfilesPuesto(): HasMany
    {
        return $this->hasMany(PruebaPerfilPuesto::class, 'prueba_id')->orderBy('orden')->orderBy('id');
    }

    public function dimensiones(): HasMany
    {
        return $this->hasMany(PruebaDimension::class, 'prueba_id');
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(PruebaPregunta::class, 'prueba_id')->orderBy('orden')->orderBy('id');
    }
}
