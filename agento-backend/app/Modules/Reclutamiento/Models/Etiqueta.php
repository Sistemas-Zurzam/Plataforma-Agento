<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'nombre', 'tipo'])]
class Etiqueta extends Model
{
    public const MANUAL = 'manual';

    public const AUTOMATICA = 'automatica';

    protected $table = 'seleccion_etiquetas';
}
