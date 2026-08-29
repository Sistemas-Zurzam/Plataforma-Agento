<?php

namespace App\Modules\Personas\Models;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'empresa_id', 'sede_id', 'area_id', 'horario_id', 'legajo',
    'nombres', 'apellidos', 'apellido_paterno', 'apellido_materno', 'tipo_documento', 'numero_documento',
    'fecha_nacimiento', 'pais_residencia', 'domiciliado', 'ciudad_residencia', 'distrito_residencia',
    'direccion', 'email', 'celular_colaborador', 'celular_referencia',
    'cargo', 'tipo_contrato', 'regimen_laboral', 'tipo_trabajador', 'categoria_trabajador', 'es_trabajador_confianza',
    'contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra', 'fecha_ingreso', 'fecha_fin_contrato', 'fecha_cese', 'motivo_cese',
    'cts_cuenta', 'sistema_previsional', 'afp_id', 'tipo_comision', 'cuspp', 'tiene_hijos_asignacion_familiar',
    'tiene_suspension_renta_4ta',
    'banco', 'banco_id', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci',
    'modalidad_trabajo', 'tolerancia_particular_minutos', 'activo',
])]
class Colaborador extends Model
{
    use SoftDeletes;

    /**
     * Catálogo único de regímenes laborales — antes vivía duplicado como
     * literal en StoreColaboradorRequest, ColaboradorController::update() y
     * actualizarConfiguracionNomina(), y ya había divergido entre ellos
     * (update() aceptaba cualquier string sin validar contra este catálogo).
     */
    public const REGIMENES_LABORALES = ['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios'];

    /**
     * Solo aplica cuando tipo_trabajador = "trabajador" — distingue
     * Empleado/Obrero para Catálogos SUNAT (Tabla 8: 21/20), sin que Agento
     * dependa del código SUNAT como valor interno.
     */
    public const CATEGORIAS_TRABAJADOR = ['empleado', 'obrero'];
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
            'fecha_cese' => 'date',
            'contabilizar_tardanzas' => 'boolean',
            'contabilizar_faltas' => 'boolean',
            'contabilizar_horas_extra' => 'boolean',
            'es_trabajador_confianza' => 'boolean',
            'tiene_hijos_asignacion_familiar' => 'boolean',
            'tiene_suspension_renta_4ta' => 'boolean',
            'domiciliado' => 'boolean',
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

    public function afp(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Configuracion\Models\Afp::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Configuracion\Models\Banco::class);
    }

    public function remuneraciones(): HasMany
    {
        return $this->hasMany(ColaboradorRemuneracion::class)->orderByDesc('vigencia_desde');
    }

    /**
     * La más reciente por vigencia_desde (empate roto por id) — NUNCA usar
     * `remuneraciones()->limit(1)` en un eager load: ese límite se aplica al
     * resultado combinado de TODOS los colaboradores de la consulta, no uno
     * por colaborador (bug real detectado: solo 1 colaborador de la página
     * terminaba con remuneración cargada). `ofMany` sí resuelve "el más
     * reciente por padre" correctamente en un solo query.
     */
    public function remuneracionVigente(): HasOne
    {
        return $this->hasOne(ColaboradorRemuneracion::class)->ofMany(
            ['vigencia_desde' => 'max', 'id' => 'max'],
        );
    }

    public function condicionesLaborales(): HasMany
    {
        return $this->hasMany(ColaboradorCondicionLaboral::class)->orderByDesc('vigencia_desde');
    }

    /**
     * La más reciente por vigencia_desde — mismo criterio `ofMany` que
     * remuneracionVigente() para evitar el bug de "limit(1) en eager load
     * combina todos los colaboradores de la página".
     */
    public function condicionLaboralVigente(): HasOne
    {
        return $this->hasOne(ColaboradorCondicionLaboral::class)->ofMany(
            ['vigencia_desde' => 'max', 'id' => 'max'],
        );
    }

    public function calendario(): HasMany
    {
        return $this->hasMany(ColaboradorCalendarioDia::class)->orderBy('fecha');
    }

    public function asignacionesHorario(): HasMany
    {
        return $this->hasMany(ColaboradorHorarioAsignacion::class)
            ->orderByDesc('vigencia_desde');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(ColaboradorDocumento::class);
    }

    public function resultadosAsistencia(): HasMany
    {
        return $this->hasMany(AsistenciaResultadoDiario::class);
    }

    public function incidenciasAsistencia(): HasMany
    {
        return $this->hasMany(AsistenciaIncidencia::class);
    }

    public function marcacionesAsistencia(): HasMany
    {
        return $this->hasMany(AsistenciaMarcacion::class);
    }

    /**
     * A diferencia de otros modelos con empresa_id, Colaborador NO usa
     * #[ScopedBy([EmpresaScope::class])]: ColaboradorService::listar() /
     * estadisticas() / colaboradoresRotativosSinRol() y la importación Excel
     * consultan legítimamente colaboradores de VARIAS empresas autorizadas a
     * la vez (modo "todas las empresas" de un admin, o un archivo con filas
     * de distintas empresas del mismo grupo) — un scope global filtraría
     * eso al vuelo a una sola empresa y rompería ambas funcionalidades.
     *
     * En su lugar, se protege acá específicamente la resolución implícita
     * de {colaborador} en rutas (Route::model binding), que es el vector
     * real que un nuevo endpoint podría olvidar verificar manualmente: si
     * el id no pertenece a ninguna empresa autorizada del usuario, el
     * binding ya falla con 404 antes de llegar al controller, sin afectar
     * ninguna consulta explícita del resto del código (que sigue filtrando
     * por empresa_id a mano).
     *
     * Se valida contra TODAS las empresas autorizadas (empresa_usuario), no
     * solo la empresa activa — igual que resolverEmpresaIds() en
     * ColaboradorController: rutas como "conceptos de un ciclo de otra
     * empresa autorizada" (ver CicloRemunerativoController) referencian un
     * colaborador que puede no pertenecer a la empresa activa de la sesión.
     * Un administrador global no necesita fila en empresa_user por cada
     * empresa (ver User::esAdministradorGlobal), así que no se filtra.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $usuario = auth('api')->user();
        $query = $this->where($field ?? $this->getRouteKeyName(), $value);

        if ($usuario && ! $usuario->esAdministradorGlobal()) {
            $query->whereIn('empresa_id', $usuario->empresas()->pluck('empresas.id'));
        }

        return $query->firstOrFail();
    }
}
