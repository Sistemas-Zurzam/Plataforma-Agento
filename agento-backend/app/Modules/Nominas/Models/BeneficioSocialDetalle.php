<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['beneficio_social_id', 'colaborador_id', 'sueldo_basico', 'meses', 'bruta', 'bonificacion_extraordinaria', 'neta', 'detalle_calculo', 'estado_deposito'])]
class BeneficioSocialDetalle extends Model
{
    protected $table = 'beneficio_social_detalles';

    protected function casts(): array
    {
        return [
            'sueldo_basico' => 'decimal:2',
            'bruta' => 'decimal:2',
            'bonificacion_extraordinaria' => 'decimal:2',
            'neta' => 'decimal:2',
            'detalle_calculo' => 'array',
        ];
    }

    public function beneficioSocial(): BelongsTo
    {
        return $this->belongsTo(BeneficioSocial::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function depositos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BeneficioSocialDeposito::class, 'beneficio_social_detalle_id');
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $query=$this->newQuery()->where($field??$this->getRouteKeyName(),$value);
        if($empresaId=auth('api')->user()?->empresa_id){
            $query->whereHas('beneficioSocial',fn($q)=>$q->where('empresa_id',$empresaId));
        }
        return $query->firstOrFail();
    }
}
