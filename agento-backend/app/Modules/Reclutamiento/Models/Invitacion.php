<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Hidden(['token_hash'])]
#[Fillable(['empresa_id', 'proceso_id', 'candidato_id', 'email', 'token_hash', 'tipo', 'expira_at', 'usada_at', 'creado_por'])]
class Invitacion extends Model
{
    public const INTERNA = 'interna';

    public const BANCO = 'banco';

    protected $table = 'seleccion_invitaciones';

    protected function casts(): array
    {
        return ['expira_at' => 'datetime', 'usada_at' => 'datetime'];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(ProcesoSeleccion::class, 'proceso_id');
    }
}
