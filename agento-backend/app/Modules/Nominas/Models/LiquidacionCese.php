<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['empresa_id', 'colaborador_id', 'fecha_cese', 'motivo_cese', 'remuneracion_snapshot', 'regimen_laboral_snapshot', 'modo', 'sustento', 'datos_calculo', 'incluir_remuneracion', 'incluir_cts', 'incluir_gratificacion', 'incluir_vacaciones', 'total_ingresos', 'total_egresos', 'neto_pagar', 'alertas', 'estado', 'version', 'es_version_vigente', 'calculado_por', 'calculado_at', 'aprobado_por', 'aprobado_at', 'pagado_por', 'pagado_at', 'referencia_pago', 'fecha_pago', 'medio_pago', 'anulado_por', 'anulado_at', 'motivo_anulacion'])]
class LiquidacionCese extends Model
{
    public const MODO_ASISTIDO = 'asistido';
    public const MODO_MANUAL = 'manual';
    /** Liquidaciones creadas al cesar, antes de separar cese y liquidación (incluían el sueldo del mes). */
    public const MODO_LEGADO = 'legado';

    protected $table = 'liquidaciones_cese';

    protected function casts(): array
    {
        return [
            'fecha_cese' => 'date', 'calculado_at' => 'datetime', 'fecha_pago' => 'date',
            'incluir_remuneracion' => 'boolean', 'incluir_cts' => 'boolean',
            'incluir_gratificacion' => 'boolean', 'incluir_vacaciones' => 'boolean',
            'es_version_vigente' => 'boolean', 'remuneracion_snapshot' => 'decimal:2',
            'total_ingresos' => 'decimal:2', 'total_egresos' => 'decimal:2', 'neto_pagar' => 'decimal:2',
            'alertas' => 'array', 'datos_calculo' => 'array', 'aprobado_at' => 'datetime', 'pagado_at' => 'datetime', 'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo { return $this->belongsTo(Empresa::class); }
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
    public function conceptos(): HasMany { return $this->hasMany(LiquidacionCeseConcepto::class); }
}
