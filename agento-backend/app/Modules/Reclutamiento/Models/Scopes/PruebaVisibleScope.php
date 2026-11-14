<?php

namespace App\Modules\Reclutamiento\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Pruebas visibles para el usuario autenticado: las de su empresa activa y
 * las del banco único de Agento (empresa_id NULL). Nunca las de otra
 * empresa. Sin usuario autenticado (portal público por token, comandos) no
 * filtra, igual que EmpresaScope.
 */
class PruebaVisibleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($empresaId = auth('api')->user()?->empresa_id) {
            $columna = $model->getTable().'.empresa_id';
            $builder->where(fn (Builder $q) => $q->where($columna, $empresaId)->orWhereNull($columna));
        }
    }
}
