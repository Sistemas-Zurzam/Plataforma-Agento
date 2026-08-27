<?php

namespace App\Modules\Personas\Models;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'colaborador_id', 'horario_id', 'dias_descanso_rotativo_por_semana', 'vigencia_desde', 'vigencia_hasta'])]
class ColaboradorHorarioAsignacion extends Model
{
    protected $table = 'colaborador_horario_asignaciones';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }
}
