<?php

namespace App\Modules\Configuracion\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EmpresaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($empresaId = auth('api')->user()?->empresa_id) {
            $builder->where($model->getTable().'.empresa_id', $empresaId);
        }
    }
}
