<?php

namespace App\Modules\Configuracion\Models;

use App\Models\User;
use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nombre_comercial',
    'razon_social',
    'abreviatura',
    'grupo',
    'ruc',
    'direccion',
    'color',
    'logo_path',
    'plantilla_carnet',
    'regimen_laboral',
    'inscrita_remype',
    'fecha_inscripcion_remype',
    'numero_registro_remype',
    'seguro_salud',
    'activa',
    'descanso_flexible_automatico',
    'bono_asistencia_habilitado',
    'servicio_planilla',
    'servicio_reclutamiento',
])]
class Empresa extends Model
{
    /**
     * Servicios que Agento presta a la empresa. Habilitan las secciones del
     * Portal de Empresa y los módulos internos (ver EnsureServicioContratado).
     */
    public const SERVICIOS = ['planilla', 'reclutamiento'];

    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    /**
     * HasFactory no puede adivinar la factory para modelos fuera de
     * App\Models — hay que indicarla explícitamente.
     */
    protected static function newFactory(): EmpresaFactory
    {
        return EmpresaFactory::new();
    }

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'descanso_flexible_automatico' => 'boolean',
            'bono_asistencia_habilitado' => 'boolean',
            'servicio_planilla' => 'boolean',
            'servicio_reclutamiento' => 'boolean',
            'inscrita_remype' => 'boolean',
            'fecha_inscripcion_remype' => 'date',
        ];
    }

    public function tieneServicio(string $servicio): bool
    {
        return in_array($servicio, self::SERVICIOS, true) && (bool) $this->{"servicio_{$servicio}"};
    }

    /**
     * @return array{planilla: bool, reclutamiento: bool}
     */
    public function servicios(): array
    {
        return ['planilla' => $this->tieneServicio('planilla'), 'reclutamiento' => $this->tieneServicio('reclutamiento')];
    }

    /** Seguro de salud que debe usarse en la planilla según el régimen vigente. */
    public function seguroSaludAplicable(): string
    {
        if ($this->regimen_laboral === 'Micro Empresa' && $this->inscrita_remype) {
            return 'sis';
        }

        return $this->seguro_salud ?: 'essalud';
    }

    /**
     * Users with access to this empresa, with their role in it.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(EmpresaUsuario::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function reglasDescuentoTardanza(): HasMany
    {
        return $this->hasMany(ReglaDescuentoTardanza::class)->orderBy('orden');
    }

    public function cuentasBancarias(): HasMany
    {
        return $this->hasMany(EmpresaCuentaBancaria::class);
    }
}
