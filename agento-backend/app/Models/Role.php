<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clave', 'nombre'])]
class Role extends Model
{
    public const ADMINISTRADOR = 'administrador';

    public static function administrador(): self
    {
        return static::where('clave', self::ADMINISTRADOR)->firstOrFail();
    }

    public function empresaUsuarios(): HasMany
    {
        return $this->hasMany(EmpresaUsuario::class);
    }
}
