<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'colaborador_id', 'person_id', 'marcado_at', 'origen',
    'dispositivo', 'datos_origen', 'anulada_at', 'anulada_por',
])]
class AsistenciaMarcacion extends Model
{
    /** Único origen con constante propia — los demás ('transaction',
     * 'manual_rrhh', etc.) son literales sueltos ya establecidos; esta se
     * agrega porque el nuevo origen se referencia desde varios archivos
     * (servicio, tests, mapper de presentación) y evitar un typo ahí sí
     * importa. */
    public const ORIGEN_CARNET_CODIGO_BARRAS = 'carnet_codigo_barras';

    /** Registro manual desde el kiosco cuando el colaborador olvidó el
     * carnet — el vigilante lo busca por nombre y confirma su identidad
     * viendo la foto antes de registrar (nunca es autoservicio: siempre
     * hay una persona verificando). Distinto de 'manual_rrhh' (que es una
     * corrección hecha por RRHH desde Gestión de Asistencias, no desde el
     * kiosco). */
    public const ORIGEN_MANUAL_VIGILANCIA = 'manual_vigilancia';

    protected $table = 'asistencia_marcaciones';

    protected function casts(): array
    {
        return [
            'marcado_at' => 'datetime',
            'datos_origen' => 'array',
            'anulada_at' => 'datetime',
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

    public function importaciones(): BelongsToMany
    {
        return $this->belongsToMany(
            AsistenciaImportacion::class,
            'asistencia_importacion_marcaciones',
            'marcacion_id',
            'importacion_id'
        )->withPivot('fue_nueva')->withTimestamps();
    }
}
