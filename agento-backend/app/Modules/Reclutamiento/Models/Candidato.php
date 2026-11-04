<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identidad y contacto de una persona candidata DENTRO de una empresa. La
 * misma persona que postula a dos empresas tiene dos registros: el banco de
 * talentos nunca comparte datos entre empresas.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'email', 'email_verificado_at', 'tipo_documento', 'numero_documento', 'nombres',
    'apellido_paterno', 'apellido_materno', 'celular', 'ubicacion', 'resumen', 'ultima_postulacion_at',
    'anonimizado_at',
])]
class Candidato extends Model
{
    protected $table = 'seleccion_candidatos';

    protected function casts(): array
    {
        return [
            'email_verificado_at' => 'datetime',
            'ultima_postulacion_at' => 'datetime',
            'anonimizado_at' => 'datetime',
        ];
    }

    public function antecedentes(): HasMany
    {
        return $this->hasMany(CandidatoAntecedente::class, 'candidato_id');
    }

    public function habilidades(): HasMany
    {
        return $this->hasMany(CandidatoHabilidad::class, 'candidato_id');
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class, 'candidato_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoCandidato::class, 'candidato_id');
    }

    public function consentimientos(): HasMany
    {
        return $this->hasMany(Consentimiento::class, 'candidato_id');
    }

    public function etiquetas(): BelongsToMany
    {
        return $this->belongsToMany(Etiqueta::class, 'seleccion_candidato_etiqueta', 'candidato_id', 'etiqueta_id')
            ->withPivot('origen')
            ->withTimestamps();
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellido_paterno} {$this->apellido_materno}");
    }
}
