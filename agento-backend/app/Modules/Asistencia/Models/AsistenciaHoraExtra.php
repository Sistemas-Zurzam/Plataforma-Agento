<?php

namespace App\Modules\Asistencia\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'resultado_diario_id', 'origen', 'colaborador_id', 'fecha', 'minutos_observados', 'minutos_solicitados', 'minutos_aprobados', 'minutos_aprobados_gerencia', 'tasa', 'estado', 'motivo', 'motivo_gerencia', 'resuelto_por', 'resuelto_at', 'gerencia_resuelto_por', 'gerencia_resuelto_at'])]
class AsistenciaHoraExtra extends Model
{
    // Estados canónicos (V3 Fase 3) — 'aprobado'/'rechazado' en masculino
    // porque así ya se usaba 'aprobado' desde el inicio para HE (a
    // diferencia de AsistenciaIncidencia, que usa 'resuelta'/'rechazada').
    public const ESTADO_PENDIENTE = 'pendiente';

    /**
     * Aprobada por gerencia desde el Portal de Empresa, pendiente de la
     * validación de RR. HH. NO se paga: Nómina solo consume ESTADO_APROBADO.
     */
    public const ESTADO_APROBADO_GERENCIA = 'aprobado_gerencia';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    /** Estados que todavía esperan una decisión final de RR. HH. */
    public const ESTADOS_PENDIENTES_RRHH = [self::ESTADO_PENDIENTE, self::ESTADO_APROBADO_GERENCIA];

    protected $table = 'asistencia_horas_extra';

    protected function casts(): array
    {
        return ['fecha' => 'date', 'resuelto_at' => 'datetime', 'gerencia_resuelto_at' => 'datetime'];
    }

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaResultadoDiario::class, 'resultado_diario_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function gerenciaResueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerencia_resuelto_por');
    }
}
