<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EmpresaUsuario extends Pivot
{
    protected $table = 'empresa_user';

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
