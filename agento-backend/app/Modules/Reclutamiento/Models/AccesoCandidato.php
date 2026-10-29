<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['token_hash'])]
#[Fillable(['email', 'token_hash', 'expira_at', 'revocado_at', 'ultimo_uso_at'])]
class AccesoCandidato extends Model
{
    protected $table = 'seleccion_accesos_candidato';

    protected function casts(): array
    {
        return ['expira_at' => 'datetime', 'revocado_at' => 'datetime', 'ultimo_uso_at' => 'datetime'];
    }
}
