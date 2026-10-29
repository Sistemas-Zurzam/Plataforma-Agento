<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['candidato_id', 'tipo', 'titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])]
class CandidatoAntecedente extends Model
{
    public const EXPERIENCIA = 'experiencia';

    public const ESTUDIO = 'estudio';

    public $timestamps = false;

    protected $table = 'seleccion_candidato_antecedentes';

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }
}
