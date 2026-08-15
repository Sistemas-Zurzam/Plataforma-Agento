<?php

namespace App\Modules\Personas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['colaborador_id', 'fecha', 'tipo'])]
class ColaboradorCalendarioDia extends Model
{
    /**
     * Eloquent pluralizaría "ColaboradorCalendarioDia" incorrectamente en
     * español; la tabla real es "colaborador_calendario_dias".
     */
    protected $table = 'colaborador_calendario_dias';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
