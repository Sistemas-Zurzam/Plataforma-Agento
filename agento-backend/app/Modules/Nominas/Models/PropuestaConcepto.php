<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta de bono o comisión (Portal de Empresa) pendiente de la
 * decisión de RR. HH. Sin EmpresaScope, igual que CicloRemunerativo: cada
 * consulta filtra empresa_id explícitamente.
 *
 * Estados: propuesto → validado (registrado como concepto del ciclo; NO
 * significa pagado) | rechazado.
 */
#[Fillable([
    'empresa_id', 'ciclo_id', 'colaborador_id', 'tipo', 'monto', 'motivo', 'origen', 'estado',
    'observacion_rrhh', 'propuesto_por', 'resuelto_por', 'resuelto_at', 'colaborador_concepto_periodo_id',
    'clave_idempotencia',
])]
class PropuestaConcepto extends Model
{
    public const ESTADO_PROPUESTO = 'propuesto';

    public const ESTADO_VALIDADO = 'validado';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const TIPOS = ['bono', 'comision'];

    protected $table = 'nomina_propuestas_concepto';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'resuelto_at' => 'datetime'];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function proponente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propuesto_por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function conceptoPeriodo(): BelongsTo
    {
        return $this->belongsTo(ColaboradorConceptoPeriodo::class, 'colaborador_concepto_periodo_id');
    }
}
