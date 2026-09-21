<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Factories\SaldoVacacionalHistoricoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo vacacional consolidado y aprobado a una fecha de corte (ej.
 * 31/07/2026) — ver docblock de la migración para la decisión de NO
 * escribir automáticamente en `vacacion_movimientos` desde este incremento.
 *
 * Incremento 1: solo almacena el dato. Ninguna fórmula de liquidación lo
 * consume todavía.
 */
#[Fillable([
    'empresa_id', 'colaborador_id', 'importacion_detalle_id',
    'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'fecha_corte',
    'dias_devengados', 'dias_gozados', 'dias_pagados', 'dias_pendientes',
    'estado', 'origen', 'observaciones',
    'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
])]
class SaldoVacacionalHistorico extends Model
{
    /** @use HasFactory<SaldoVacacionalHistoricoFactory> */
    use HasFactory;

    public const ESTADOS = ['borrador', 'aprobado', 'anulado'];

    protected $table = 'saldos_vacacionales_historicos';

    protected static function newFactory(): SaldoVacacionalHistoricoFactory
    {
        return SaldoVacacionalHistoricoFactory::new();
    }

    protected function casts(): array
    {
        return [
            'fecha_ingreso_vinculo' => 'date',
            'fecha_fin_vinculo' => 'date',
            'fecha_corte' => 'date',
            'dias_devengados' => 'decimal:4',
            'dias_gozados' => 'decimal:4',
            'dias_pagados' => 'decimal:4',
            'dias_pendientes' => 'decimal:4',
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
     * Dato de negocio puro (no fórmula de liquidación): la fecha de corte
     * de un saldo nunca puede ser anterior al inicio del vínculo que dice
     * representar. Ningún motor soporta un CHECK constraint portable entre
     * dos columnas de forma nativa en el Blueprint de Laravel — ver informe
     * de Incremento 1. Todavía no se invoca automáticamente.
     */
    public function fechaCorteEsValida(): bool
    {
        return $this->fecha_corte !== null
            && $this->fecha_ingreso_vinculo !== null
            && $this->fecha_corte->gte($this->fecha_ingreso_vinculo);
    }

    /**
     * Dato de negocio puro: un saldo de días pendientes nunca puede ser
     * negativo. La columna es `unsigned` (protección real en MySQL); SQLite
     * no aplica esa semántica, así que este método es la única protección
     * efectiva en el entorno de pruebas — ver informe de Incremento 1.
     */
    public function diasPendientesEsValido(): bool
    {
        return $this->dias_pendientes !== null && bccomp((string) $this->dias_pendientes, '0', 4) >= 0;
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
        return $query->where('estado', 'aprobado');
    }

    public function scopeHastaFechaCorte(Builder $query, string $fecha): Builder
    {
        return $query->whereDate('fecha_corte', '<=', $fecha);
    }
}
