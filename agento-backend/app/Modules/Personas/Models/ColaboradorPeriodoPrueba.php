<?php

namespace App\Modules\Personas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'colaborador_id', 'fecha_inicio', 'fecha_fin', 'resultado', 'observacion', 'registrado_por'])]
class ColaboradorPeriodoPrueba extends Model
{
    public const RESULTADOS = ['en_curso', 'superado', 'no_superado'];

    protected $table = 'colaborador_periodos_prueba';

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
