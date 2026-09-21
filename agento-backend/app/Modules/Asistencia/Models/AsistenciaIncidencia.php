<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'resultado_diario_id', 'colaborador_id', 'fecha', 'tipo',
    'estado', 'descripcion', 'motivo_resolucion', 'resuelto_por', 'resuelto_at',
])]
class AsistenciaIncidencia extends Model
{
    // Tipos automáticos (los únicos que ProcesarAsistenciaDiaria genera solo).
    // Centralizados acá para no repetir los strings en Services/Controllers.
    public const TIPO_FALTA = 'falta';

    public const TIPO_MARCACION_INCOMPLETA = 'marcacion_incompleta';

    public const TIPO_HORARIO_DESPLAZADO = 'horario_desplazado';

    public const TIPO_HORAS_INCOMPLETAS = 'horas_incompletas';

    // Rotativo Fase 1 — día de un colaborador con horario rotativo sin
    // planificación (colaborador_calendario_dias) para esa fecha exacta.
    // Nunca se adivina si era descanso o laborable (con o sin marcaciones,
    // ver ProcesarAsistenciaDiaria::procesar()) — queda pendiente hasta que
    // RR.HH. lo clasifique con una acción de dominio explícita
    // (AsistenciaDecisionService::resolverDiaSinClasificar()), nunca con el
    // aprobar/rechazar genérico de las demás incidencias automáticas.
    public const TIPO_DIA_SIN_CLASIFICAR = 'dia_sin_clasificar';

    // Fase 3.1 — colaborador con marcaciones reales sobre un día planificado
    // como descanso (cualquier horario, fijo o rotativo). A diferencia de
    // los demás tipos automáticos, esta NO reemplaza el `estado` del
    // resultado diario (que sigue siendo 'presente' normal) — coexiste con
    // él para que RR.HH. decida si corresponde pago adicional, descanso
    // sustitutorio, o si la planificación estaba mal (ver
    // AsistenciaDecisionService::resolverTrabajoEnDescanso()). Por eso vive
    // fuera de sincronizarIncidencia() (esa función asocia 1 estado → 1
    // tipo automático; este evento es ortogonal al estado).
    public const TIPO_TRABAJO_EN_DESCANSO = 'trabajo_en_descanso';

    // Descanso semanal flexible automático (opt-in, Empresa::descanso_flexible_automatico)
    // — generadas únicamente por EvaluarIntegridadDescansoSemanal, ancladas al
    // resultado diario del DOMINGO de la semana evaluada (reutiliza la unique
    // (resultado_diario_id, tipo) ya existente, sin columna nueva). Nunca se
    // infieren de "cero candidatos": se calculan contando días efectivamente
    // trabajados. Mutuamente excluyentes entre sí para una misma semana.
    //
    // Severa: la semana no tuvo NINGÚN descanso y el colaborador trabajó
    // efectivamente todos los días aplicables (sin permiso/feriado). Se
    // resuelve marcando a mano uno de esos días como descanso — eso dispara
    // TIPO_TRABAJO_EN_DESCANSO, que ya tiene pago/sustitutorio/corregir.
    public const TIPO_SIN_DESCANSO_SEMANAL = 'sin_descanso_semanal';

    // General: se asignaron menos descansos automáticos de los que
    // dias_descanso_rotativo_por_semana exige (p. ej. un permiso cubrió
    // parte de la semana y no quedaron suficientes días candidatos).
    public const TIPO_DESCANSO_FLEXIBLE_INCOMPLETO = 'descanso_flexible_incompleto';

    // Generada por AsignarDescansoFlexibleSemanal (no por el evaluador de
    // integridad) cuando un segmento no pudo clasificarse por datos
    // insuficientes o un error técnico — NUNCA por el cruce normal de
    // periodos, que se resuelve solo por segmentos sin necesitar esto.
    public const TIPO_SEMANA_ROTATIVA_OMITIDA = 'semana_rotativa_omitida';

    // Endurecimiento Asistencia -> Nómina (v5, incremento 3) — generada por
    // App\Modules\Nominas\Application\NotificarCambioAsistenciaCiclo cuando
    // algo cambia en Asistencia para una fecha cuyo CicloRemunerativo ya
    // está 'pagado'. La boleta pagada nunca se modifica ni se recalcula
    // sola — esta incidencia es la señal para que RR.HH. evalúe una
    // PlanillaComplementaria. Se ancla al resultado diario más cercano al
    // rango notificado (no siempre existe un "domingo" natural como en las
    // incidencias semanales de descanso flexible).
    public const TIPO_AJUSTE_POST_PAGO_PENDIENTE = 'ajuste_post_pago_pendiente';

    // Estados posibles del ciclo de resolución de una incidencia.
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_RESUELTA = 'resuelta';

    public const ESTADO_RECHAZADA = 'rechazada';

    protected $table = 'asistencia_incidencias';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'resuelto_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaResultadoDiario::class, 'resultado_diario_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
