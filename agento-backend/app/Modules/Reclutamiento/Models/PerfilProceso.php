<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión inmutable del perfil de un proceso: requisitos, competencias,
 * umbral ATS y pesos del puntaje final. Cambiar el perfil crea una versión
 * nueva; las evaluaciones guardan la versión con la que se calcularon.
 */
#[Fillable(['proceso_id', 'version', 'umbral_ats', 'peso_ats', 'peso_psicometria', 'peso_entrevistas', 'creado_por'])]
class PerfilProceso extends Model
{
    protected $table = 'seleccion_perfiles';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'umbral_ats' => 'integer',
            'peso_ats' => 'integer',
            'peso_psicometria' => 'integer',
            'peso_entrevistas' => 'integer',
        ];
    }

    public function requisitos(): HasMany
    {
        return $this->hasMany(PerfilRequisito::class, 'perfil_id')->orderBy('orden');
    }

    public function competencias(): HasMany
    {
        return $this->hasMany(PerfilCompetencia::class, 'perfil_id');
    }

    /**
     * @return array{ats: int, psicometria: int, entrevistas: int}
     */
    public function pesos(): array
    {
        return ['ats' => $this->peso_ats, 'psicometria' => $this->peso_psicometria, 'entrevistas' => $this->peso_entrevistas];
    }
}
