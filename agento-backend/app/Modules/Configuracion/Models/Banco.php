<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global de identidad de banco — `codigo` es la clave interna
 * estable de Agento (ej. 'bcp'), nunca un código bancario externo. Ver
 * migración crear_bancos para el alcance (solo identidad, sin
 * SWIFT/agencia/tasas).
 */
#[Fillable(['codigo', 'nombre', 'activo'])]
class Banco extends Model
{
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function cuentasEmpresa(): HasMany
    {
        return $this->hasMany(EmpresaCuentaBancaria::class);
    }
}
