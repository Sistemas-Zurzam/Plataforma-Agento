<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Factories\NominaImportacionHistoricaDetalleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fila temporal (staging) de un lote de importación histórica — una por
 * cada fila del Excel, antes de convertirse en un antecedente definitivo
 * (BeneficioSocialHistorico / SaldoVacacionalHistorico / SaldoLaboralPendiente).
 * Incremento 1: solo el esquema y el modelo; el importador que llena estas
 * filas a partir del Excel real es Incremento 2.
 */
#[Fillable([
    'importacion_id', 'empresa_id', 'hoja_nombre', 'fila_numero', 'datos_originales',
    'tipo_documento_normalizado', 'numero_documento_normalizado', 'colaborador_nombre_original', 'colaborador_id',
    'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'empresa_informada', 'regimen_informado',
    'tipo_calculo_original', 'tipo_antecedente', 'clasificacion', 'codigo_concepto_original', 'nombre_concepto_original',
    'anio', 'mes', 'fecha_periodo_inicio', 'fecha_periodo_fin', 'fecha_pago_deposito', 'fecha_corte',
    'importe', 'dias_cantidad', 'estado_excel', 'estado_validacion', 'errores', 'advertencias',
    'fingerprint_negocio', 'referencia_pago_confirmada', 'fecha_pago_confirmada',
])]
class NominaImportacionHistoricaDetalle extends Model
{
    /** @use HasFactory<NominaImportacionHistoricaDetalleFactory> */
    use HasFactory;

    /**
     * `ignorado` es el destino final de una fila `clasificacion=no_aplicable`
     * — nunca se queda en `pendiente` (que ahora significa exclusivamente
     * "todavía no clasificada", un estado transitorio dentro de importar()).
     */
    public const ESTADOS_VALIDACION = ['pendiente', 'valido', 'observado', 'error', 'ignorado', 'aplicado'];

    /**
     * Distinta de `estado_validacion`: es la decisión de negocio del
     * clasificador (qué tipo de fila es), nunca se asigna a mano — ver
     * ImportarAntecedentesHistoricosService::CAMPOS_CORREGIBLES, que
     * excluye deliberadamente este campo.
     */
    public const CLASIFICACIONES = ['aplicable', 'no_aplicable', 'observado', 'error'];

    public const TIPOS_ANTECEDENTE = [
        'gratificacion_pagada', 'cts_depositada', 'saldo_vacacional', 'vacaciones_gozadas',
        'prestamo_pendiente', 'adelanto_pendiente', 'descuento_pendiente',
        'gratificacion_trunca_pagada', 'cts_trunca_pagada', 'liquidacion_historica', 'otro',
    ];

    protected $table = 'nomina_importacion_historica_detalles';

    protected static function newFactory(): NominaImportacionHistoricaDetalleFactory
    {
        return NominaImportacionHistoricaDetalleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'datos_originales' => 'array',
            'fecha_ingreso_vinculo' => 'date',
            'fecha_fin_vinculo' => 'date',
            'anio' => 'integer',
            'mes' => 'integer',
            'fecha_periodo_inicio' => 'date',
            'fecha_periodo_fin' => 'date',
            'fecha_pago_deposito' => 'date',
            'fecha_corte' => 'date',
            'importe' => 'decimal:2',
            'dias_cantidad' => 'decimal:4',
            'errores' => 'array',
            'advertencias' => 'array',
            'fecha_pago_confirmada' => 'date',
        ];
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(NominaImportacionHistorica::class, 'importacion_id');
    }

    public function correcciones(): HasMany
    {
        return $this->hasMany(NominaImportacionHistoricaDetalleCorreccion::class, 'detalle_id')->orderByDesc('corregido_at');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->where('empresa_id', $empresaId);
    }

    public function scopeParaColaborador(Builder $query, int $colaboradorId): Builder
    {
        return $query->where('colaborador_id', $colaboradorId);
    }

    public function scopeParaVinculo(Builder $query, int $colaboradorId, string $fechaIngresoVinculo): Builder
    {
        return $query->where('colaborador_id', $colaboradorId)->whereDate('fecha_ingreso_vinculo', $fechaIngresoVinculo);
    }

    public function scopeParaClasificacion(Builder $query, string $clasificacion): Builder
    {
        return $query->where('clasificacion', $clasificacion);
    }

    public function scopeParaEstadoValidacion(Builder $query, string $estadoValidacion): Builder
    {
        return $query->where('estado_validacion', $estadoValidacion);
    }
}
