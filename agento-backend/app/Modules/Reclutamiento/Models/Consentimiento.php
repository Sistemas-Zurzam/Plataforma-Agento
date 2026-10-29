<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['empresa_id', 'candidato_id', 'version', 'texto_hash', 'aceptado_at', 'ip', 'revocado_at'])]
class Consentimiento extends Model
{
    protected $table = 'seleccion_consentimientos';

    protected function casts(): array
    {
        return ['aceptado_at' => 'datetime', 'revocado_at' => 'datetime'];
    }
}
