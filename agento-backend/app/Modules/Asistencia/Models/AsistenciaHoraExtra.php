<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'resultado_diario_id', 'colaborador_id', 'fecha', 'minutos_observados', 'minutos_solicitados', 'minutos_aprobados', 'tasa', 'estado', 'motivo', 'resuelto_por', 'resuelto_at'])]
class AsistenciaHoraExtra extends Model
{
    // Estados canónicos (V3 Fase 3) — 'aprobado'/'rechazado' en masculino
    // porque así ya se usaba 'aprobado' desde el inicio para HE (a
    // diferencia de AsistenciaIncidencia, que usa 'resuelta'/'rechazada').
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    protected $table = 'asistencia_horas_extra';

    protected function casts(): array
    {
        return ['fecha' => 'date', 'resuelto_at' => 'datetime'];
    }

    public function resultado(): BelongsTo { return $this->belongsTo(AsistenciaResultadoDiario::class, 'resultado_diario_id'); }
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
}
