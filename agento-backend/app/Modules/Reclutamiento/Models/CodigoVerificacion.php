<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['codigo_hash'])]
#[Fillable(['email_hash', 'codigo_hash', 'intentos', 'expira_at', 'consumido_at', 'ip'])]
class CodigoVerificacion extends Model
{
    protected $table = 'seleccion_codigos_verificacion';

    protected function casts(): array
    {
        return ['expira_at' => 'datetime', 'consumido_at' => 'datetime', 'intentos' => 'integer'];
    }
}
