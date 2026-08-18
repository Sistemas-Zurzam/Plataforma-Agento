<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'periodo_id', 'colaborador_id', 'horario_asignacion_id',
    'fecha', 'tipo_dia', 'estado', 'entrada_at', 'salida_at',
    'minutos_programados', 'minutos_trabajados', 'minutos_tardanza',
    'minutos_salida_anticipada', 'minutos_extra_observados',
    'minutos_extra_25', 'minutos_extra_35', 'minutos_extra_100', 'procesado_at',
])]
class AsistenciaResultadoDiario extends Model
{
    protected $table = 'asistencia_resultados_diarios';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'entrada_at' => 'datetime',
            'salida_at' => 'datetime',
            'procesado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(AsistenciaPeriodo::class, 'periodo_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function asignacionHorario(): BelongsTo
    {
        return $this->belongsTo(ColaboradorHorarioAsignacion::class, 'horario_asignacion_id');
    }

    public function marcaciones(): BelongsToMany
    {
        return $this->belongsToMany(
            AsistenciaMarcacion::class,
            'asistencia_resultado_marcaciones',
            'resultado_diario_id',
            'marcacion_id'
        )->withTimestamps();
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(AsistenciaIncidencia::class, 'resultado_diario_id');
    }

    public function horasExtra(): HasMany
    {
        return $this->hasMany(AsistenciaHoraExtra::class, 'resultado_diario_id');
    }
}
