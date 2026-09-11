<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Factories\SaldoLaboralPendienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préstamo, adelanto o descuento con saldo pendiente anterior a agosto de
 * 2026. `saldo_pendiente` es una columna generada por la base de datos
 * (`importe_original - importe_aplicado`, ver migración) — nunca se asigna
 * a mano, por eso no aparece en `$fillable`.
 *
 * Incremento 1: solo almacena el antecedente. Ninguna liquidación lo
 * descuenta todavía.
 */
#[Fillable([
    'empresa_id', 'colaborador_id', 'importacion_detalle_id',
    'fecha_ingreso_vinculo', 'fecha_fin_vinculo',
    'tipo', 'descripcion', 'importe_original', 'importe_aplicado',
    'fecha_corte', 'estado', 'origen', 'referencia_externa', 'observaciones',
    'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
])]
class SaldoLaboralPendiente extends Model
{
    /** @use HasFactory<SaldoLaboralPendienteFactory> */
    use HasFactory;

    public const TIPOS = ['prestamo', 'adelanto', 'descuento', 'otro'];

    public const ESTADOS = ['borrador', 'aprobado', 'aplicado_parcial', 'aplicado', 'anulado'];

    protected $table = 'saldos_laborales_pendientes';

    protected static function newFactory(): SaldoLaboralPendienteFactory
    {
        return SaldoLaboralPendienteFactory::new();
    }

    protected function casts(): array
    {
        return [
            'fecha_ingreso_vinculo' => 'date',
            'fecha_fin_vinculo' => 'date',
            'importe_original' => 'decimal:2',
            'importe_aplicado' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'fecha_corte' => 'date',
            'aprobado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function importacionDetalle(): BelongsTo
    {
        return $this->belongsTo(NominaImportacionHistoricaDetalle::class, 'importacion_detalle_id');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * Dato de negocio puro (no fórmula de liquidación): lo aplicado nunca
     * puede superar el importe original. La columna generada
     * `saldo_pendiente` (unsigned) ya rechaza esto en MySQL al no caber un
     * resultado negativo; SQLite no aplica semántica "unsigned" real, así
     * que este método es la única protección efectiva en el entorno de
     * pruebas — ver informe de Incremento 1.
     */
    public function saldoEsCoherente(): bool
    {
        return $this->importe_aplicado !== null
            && $this->importe_original !== null
            && bccomp((string) $this->importe_aplicado, (string) $this->importe_original, 2) <= 0;
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

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', '!=', 'anulado');
    }

    public function scopeAprobados(Builder $query): Builder
    {
        return $query->whereIn('estado', ['aprobado', 'aplicado_parcial', 'aplicado']);
    }

    public function scopeHastaFechaCorte(Builder $query, string $fecha): Builder
    {
        return $query->whereDate('fecha_corte', '<=', $fecha);
    }
}
