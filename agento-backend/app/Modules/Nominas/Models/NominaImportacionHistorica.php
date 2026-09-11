<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use Database\Factories\NominaImportacionHistoricaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de importación de antecedentes laborales históricos (Incremento 1 —
 * ver DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md). Representa un archivo Excel
 * cargado, sin ejecutar todavía ninguna importación real: eso corresponde a
 * un servicio de Incremento 2, que hoy no existe.
 */
#[Fillable([
    'empresa_id', 'archivo_nombre_original', 'archivo_hash', 'fecha_corte', 'estado',
    'autoriza_cesados_con_liquidacion_pagada',
    'filas_totales', 'filas_validas', 'filas_observadas', 'filas_con_errores', 'filas_aplicadas',
    'filas_otra_empresa', 'filas_ignoradas',
    'metadatos', 'resumen_errores',
    'cargado_por', 'cargado_at', 'validado_por', 'validado_at',
    'aprobado_por', 'aprobado_at', 'aplicado_por', 'aplicado_at',
    'anulado_por', 'anulado_at', 'motivo_anulacion',
])]
class NominaImportacionHistorica extends Model
{
    /** @use HasFactory<NominaImportacionHistoricaFactory> */
    use HasFactory;

    public const ESTADOS = ['borrador', 'validado', 'aprobado', 'aplicado', 'anulado'];

    protected $table = 'nomina_importaciones_historicas';

    /**
     * HasFactory no puede adivinar la factory para modelos fuera de
     * App\Models — hay que indicarla explícitamente (mismo patrón que Empresa).
     */
    protected static function newFactory(): NominaImportacionHistoricaFactory
    {
        return NominaImportacionHistoricaFactory::new();
    }

    protected function casts(): array
    {
        return [
            'fecha_corte' => 'date',
            'autoriza_cesados_con_liquidacion_pagada' => 'boolean',
            'filas_totales' => 'integer',
            'filas_validas' => 'integer',
            'filas_observadas' => 'integer',
            'filas_con_errores' => 'integer',
            'filas_aplicadas' => 'integer',
            'filas_otra_empresa' => 'integer',
            'filas_ignoradas' => 'integer',
            'metadatos' => 'array',
            'resumen_errores' => 'array',
            'cargado_at' => 'datetime',
            'validado_at' => 'datetime',
            'aprobado_at' => 'datetime',
            'aplicado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(NominaImportacionHistoricaDetalle::class, 'importacion_id');
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }

    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function aplicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->where('empresa_id', $empresaId);
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', '!=', 'anulado');
    }

    public function scopeAprobados(Builder $query): Builder
    {
        return $query->whereIn('estado', ['aprobado', 'aplicado']);
    }
}
