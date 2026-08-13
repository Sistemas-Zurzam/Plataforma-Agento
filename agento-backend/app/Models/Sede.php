<?php

namespace App\Models;

use Database\Factories\SedeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'codigo', 'nombre', 'direccion', 'activa'])]
class Sede extends Model
{
    /** @use HasFactory<SedeFactory> */
    use HasFactory;

    // No lleva EmpresaScope: a diferencia de Area (que describe la empresa
    // activa del usuario), las sedes se administran desde la pantalla de
    // Empresas, donde un admin puede editar cualquier empresa a la que
    // tenga acceso, no solo la activa. La autorización se hace explícita
    // por empresa en SedeService, igual que EmpresaController::update.
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
