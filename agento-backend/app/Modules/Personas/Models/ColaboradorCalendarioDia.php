<?php

namespace App\Modules\Personas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['colaborador_id', 'fecha', 'tipo', 'origen'])]
class ColaboradorCalendarioDia extends Model
{
    /**
     * Fase 4B — de qué flujo salió esta fila, para poder distinguir en el
     * futuro (Fase 4C, todavía no implementada) una decisión humana real de
     * algo generado sin que nadie lo haya revisado. El backend es el único
     * que decide este valor según el endpoint/servicio que escribe — nunca
     * se acepta "origen" desde un Request (ver FormRequests de
     * Planificación/calendario, ninguno declara ese campo).
     *
     * NULL = origen desconocido (fila histórica, de antes de esta
     * migración). Deliberado: NUNCA se infiere con heurísticas
     * (created_at === updated_at, etc.) — la Fase 4C debe tratar NULL como
     * "no es seguro invalidar automáticamente", igual que cualquier otro
     * origen manual/humano.
     */
    public const ORIGEN_HORARIO_AUTOMATICO = 'horario_automatico';

    public const ORIGEN_FERIADO_AUTOMATICO = 'feriado_automatico';

    public const ORIGEN_WIZARD = 'wizard';

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_PLANIFICACION = 'planificacion';

    public const ORIGEN_INCIDENCIA = 'incidencia';

    public const ORIGEN_DESCANSO_SUSTITUTORIO = 'descanso_sustitutorio';

    /**
     * Descanso semanal flexible automático (opt-in por empresa, ver
     * Empresa::descanso_flexible_automatico) — asignado por
     * AsignarDescansoFlexibleSemanal al cerrar un período, nunca a mano.
     * A diferencia de ORIGEN_HORARIO_AUTOMATICO/ORIGEN_FERIADO_AUTOMATICO
     * (que sí se purgan en AjustarCalendarioPorCambioHorario::invalidarAutomaticas()),
     * este origen NUNCA debe agregarse a esa purga: su invalidación/recálculo
     * vive únicamente en AsignarDescansoFlexibleSemanal::persistirSegmento(),
     * condicionada a que el período siga abierto — mezclar ambos mecanismos
     * podría borrar una asignación ya cerrada creyendo que es un default
     * de horario reemplazable.
     */
    public const ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO = 'descanso_flexible_automatico';

    /**
     * Eloquent pluralizaría "ColaboradorCalendarioDia" incorrectamente en
     * español; la tabla real es "colaborador_calendario_dias".
     */
    protected $table = 'colaborador_calendario_dias';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
