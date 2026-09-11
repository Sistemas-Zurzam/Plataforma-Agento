<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use Database\Factories\BonoAsistenciaLoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote mensual del Bono de Asistencia (política Livex) — ver migración
 * 2026_09_08_000128_crear_bono_asistencia_lotes.php para el detalle del
 * flujo borrador→exportado→revisado→aplicado.
 *
 * Sin #[ScopedBy(EmpresaScope::class)] a propósito, igual que
 * PlanillaComplementaria: el aislamiento multiempresa lo garantiza el
 * Service (BonoAsistenciaService::verificar()), nunca un scope global.
 */
#[Fillable([
    'empresa_id', 'ciclo_id', 'nombre', 'motivo', 'estado',
    'concepto_id', 'concepto_definicion_id', 'planilla_complementaria_id',
    'archivo_exportado_nombre', 'exportado_en', 'exportado_por',
    'archivo_importado_nombre', 'revisado_en', 'revisado_por',
    'aplicado_en', 'aplicado_por',
    'anulado_en', 'anulado_por', 'motivo_anulacion',
    'creado_por',
])]
class BonoAsistenciaLote extends Model
{
    /** @use HasFactory<BonoAsistenciaLoteFactory> */
    use HasFactory;

    public const ESTADOS = ['borrador', 'exportado', 'revisado', 'aplicado', 'anulado'];

    protected $table = 'bono_asistencia_lotes';

    protected static function newFactory(): BonoAsistenciaLoteFactory
    {
        return BonoAsistenciaLoteFactory::new();
    }

    protected function casts(): array
    {
        return [
            'exportado_en' => 'datetime',
            'revisado_en' => 'datetime',
            'aplicado_en' => 'datetime',
            'anulado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoRemuneracion::class, 'concepto_id');
    }

    public function conceptoDefinicion(): BelongsTo
    {
        return $this->belongsTo(ConceptoDefinicionPlame::class, 'concepto_definicion_id');
    }

    /**
     * La complementaria que aplicar() creó para los colaboradores de este
     * lote cuya boleta ya estaba pagada — null si ninguno lo necesitó.
     */
    public function planillaComplementaria(): BelongsTo
    {
        return $this->belongsTo(PlanillaComplementaria::class, 'planilla_complementaria_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(BonoAsistenciaLoteDetalle::class, 'bono_asistencia_lote_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
