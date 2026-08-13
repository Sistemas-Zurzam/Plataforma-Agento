<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nombre',
    'abreviatura',
    'grupo',
    'ruc',
    'direccion',
    'color',
    'regimen_laboral',
    'inscrita_remype',
    'activa',
])]
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'inscrita_remype' => 'boolean',
        ];
    }

    /**
     * Users with access to this empresa, with their role in it.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(EmpresaUsuario::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
