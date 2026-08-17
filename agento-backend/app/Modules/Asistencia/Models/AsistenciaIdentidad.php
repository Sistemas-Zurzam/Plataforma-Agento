<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'colaborador_id', 'person_id'])]
class AsistenciaIdentidad extends Model
{
    protected $table = 'asistencia_identidades';
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class); }
}
