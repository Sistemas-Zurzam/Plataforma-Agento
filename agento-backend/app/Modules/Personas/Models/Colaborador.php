<?php

namespace App\Modules\Personas\Models;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id', 'sede_id', 'area_id', 'horario_id', 'legajo',
    'nombres', 'apellidos', 'tipo_documento', 'numero_documento',
    'fecha_nacimiento', 'pais_residencia', 'ciudad_residencia', 'distrito_residencia',
    'direccion', 'email', 'celular_colaborador', 'celular_referencia',
    'cargo', 'tipo_contrato', 'regimen_laboral', 'tipo_trabajador',
    'contabilizar_tardanzas', 'contabilizar_horas_extra', 'fecha_ingreso', 'fecha_fin_contrato',
    'cts_cuenta', 'sistema_previsional', 'banco', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci',
    'modalidad_trabajo', 'tolerancia_particular_minutos', 'activo',
])]
class Colaborador extends Model
{
    /**
     * Eloquent pluralizaría "Colaborador" como "colaboradors" (regla en
     * inglés); la tabla real es "colaboradores".
     */
    protected $table = 'colaboradores';

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'fecha_fin_contrato' => 'date',
            'contabilizar_tardanzas' => 'boolean',
            'contabilizar_horas_extra' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }

    public function remuneraciones(): HasMany
    {
        return $this->hasMany(ColaboradorRemuneracion::class)->orderByDesc('vigencia_desde');
    }

    public function calendario(): HasMany
    {
        return $this->hasMany(ColaboradorCalendarioDia::class)->orderBy('fecha');
    }
}
