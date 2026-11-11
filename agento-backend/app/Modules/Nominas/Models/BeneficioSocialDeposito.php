<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['beneficio_social_detalle_id','fecha_deposito','importe','entidad','referencia','constancia','idempotencia','registrado_por'])]
class BeneficioSocialDeposito extends Model
{
    protected $table='beneficio_social_depositos';
    protected function casts():array{return ['fecha_deposito'=>'date','importe'=>'decimal:2'];}
    public function detalle():BelongsTo{return $this->belongsTo(BeneficioSocialDetalle::class,'beneficio_social_detalle_id');}
}
