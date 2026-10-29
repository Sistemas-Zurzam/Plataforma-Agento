<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'proceso_id', 'candidato_id', 'codigo_seguimiento', 'fuente', 'etapa', 'estado', 'etapa_desde_at',
    'cv_documento_id', 'perfil_snapshot', 'perfil_version', 'causa_descarte', 'motivo_interno', 'mensaje_publico',
    'no_aplica_ats', 'no_aplica_psicometria', 'no_aplica_entrevistas', 'extraccion_estado', 'contratada_at',
    'colaborador_id',
])]
class Postulacion extends Model
{
    public const FUENTES = ['portal_publico', 'portal_interno', 'invitacion', 'banco_talentos', 'registro_manual'];

    protected $table = 'seleccion_postulaciones';

    protected function casts(): array
    {
        return [
            'perfil_snapshot' => 'array',
            'perfil_version' => 'integer',
            'etapa_desde_at' => 'datetime',
            'contratada_at' => 'datetime',
            'no_aplica_ats' => 'boolean',
            'no_aplica_psicometria' => 'boolean',
            'no_aplica_entrevistas' => 'boolean',
        ];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(ProcesoSeleccion::class, 'proceso_id');
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class, 'candidato_id');
    }

    public function cv(): BelongsTo
    {
        return $this->belongsTo(DocumentoCandidato::class, 'cv_documento_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(PostulacionMovimiento::class, 'postulacion_id')->orderByDesc('id');
    }

    public function evaluacionesAts(): HasMany
    {
        return $this->hasMany(EvaluacionAts::class, 'postulacion_id')->orderByDesc('id');
    }

    public function evaluacionAtsVigente(): HasOne
    {
        return $this->hasOne(EvaluacionAts::class, 'postulacion_id')->ofMany('id', 'max');
    }

    public function puntajeFinalVigente(): HasOne
    {
        return $this->hasOne(PuntajeFinal::class, 'postulacion_id')->ofMany('id', 'max');
    }

    public function entrevistas(): HasMany
    {
        return $this->hasMany(Entrevista::class, 'postulacion_id')->orderByDesc('id');
    }

    public function asignacionesPrueba(): HasMany
    {
        return $this->hasMany(PruebaAsignacion::class, 'postulacion_id');
    }

    public function plaza(): HasOne
    {
        return $this->hasOne(Plaza::class, 'postulacion_id');
    }
}
