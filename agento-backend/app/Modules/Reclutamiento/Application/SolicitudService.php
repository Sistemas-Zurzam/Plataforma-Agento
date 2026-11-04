<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\FlujoSolicitud;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SolicitudService
{
    public function __construct(
        private readonly ProcesoService $procesos,
        private readonly PostulacionService $postulaciones,
        private readonly RegistroActividad $actividad,
        private readonly ParametroLaboralService $parametros,
    ) {}

    public function listar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return SolicitudPersonal::query()
            ->where('empresa_id', $alcance->empresaId)
            ->when(! $alcance->accesoTotal(), fn ($q) => $q->where(fn ($sub) => $sub
                ->whereIn('area_id', $alcance->areaIds)
                ->orWhere('solicitante_user_id', $alcance->usuario->id)))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['busqueda'] ?? null, fn ($q, $texto) => $q->where('cargo', 'like', '%'.$texto.'%'))
            ->with(['area:id,nombre', 'solicitante:id,name', 'proceso:id,solicitud_id,codigo,estado'])
            ->orderByDesc('urgente')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @throws AuthorizationException
     */
    public function autorizarLectura(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud): void
    {
        if ($solicitud->empresa_id !== $alcance->empresaId) {
            throw new AuthorizationException('La solicitud no pertenece a la empresa activa.');
        }

        if (! $alcance->accesoTotal()
            && ! in_array($solicitud->area_id, $alcance->areaIds, true)
            && $solicitud->solicitante_user_id !== $alcance->usuario->id) {
            throw new AuthorizationException('La solicitud no pertenece a tus áreas autorizadas.');
        }
    }

    /**
     * Datos de apoyo para el formulario: áreas autorizadas y RMV vigente del
     * régimen de la empresa, tomada de Parámetros Laborales (no se fija
     * ningún valor legal en código).
     *
     * @return array<string, mixed>
     */
    public function referencias(AlcanceSeleccion $alcance): array
    {
        $empresa = Empresa::findOrFail($alcance->empresaId);
        $areas = Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
            ->when(! $alcance->accesoTotal(), fn ($q) => $q->whereIn('id', $alcance->areaIds))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $regimen = $empresa->regimen_laboral ?: 'General';
        $rmv = collect($this->parametros->listar($empresa)['regimenes'])
            ->firstWhere('regimen', $regimen)['parametros'] ?? collect();
        $parametroRmv = collect($rmv)->firstWhere('clave', 'rmv');

        return [
            'areas' => $areas,
            'rmv' => $parametroRmv['valor'] ?? null,
            'rmv_regimen' => $regimen,
            'rmv_vigencia_desde' => $parametroRmv['vigencia_desde'] ?? null,
            'motivos' => SolicitudPersonal::MOTIVOS,
            'tipos_contrato' => SolicitudPersonal::TIPOS_CONTRATO,
            'modalidades' => SolicitudPersonal::MODALIDADES,
        ];
    }

    public function crear(AlcanceSeleccion $alcance, array $datos): SolicitudPersonal
    {
        $this->validarArea($alcance, (int) $datos['area_id']);

        $solicitud = DB::transaction(function () use ($alcance, $datos) {
            $solicitud = SolicitudPersonal::create([
                ...collect($datos)->except(['habilidades', 'enviar'])->all(),
                'empresa_id' => $alcance->empresaId,
                'solicitante_user_id' => $alcance->usuario->id,
                'estado' => SolicitudPersonal::BORRADOR,
            ]);
            $this->sincronizarHabilidades($solicitud, $datos['habilidades'] ?? []);
            $this->actividad->registrar($alcance->empresaId, $solicitud, 'solicitud_creada', $alcance->usuario->id);

            return $solicitud;
        });

        if (! empty($datos['enviar'])) {
            return $this->enviar($alcance, $solicitud);
        }

        return $solicitud->load(['area', 'habilidades', 'solicitante']);
    }

    public function actualizar(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud, array $datos): SolicitudPersonal
    {
        $this->autorizarEdicion($alcance, $solicitud);
        $this->validarArea($alcance, (int) $datos['area_id']);

        DB::transaction(function () use ($alcance, $solicitud, $datos) {
            $antes = $solicitud->getAttributes();
            $solicitud->update(collect($datos)->except(['habilidades', 'enviar'])->all());
            $this->sincronizarHabilidades($solicitud, $datos['habilidades'] ?? []);
            $this->actividad->registrar(
                $alcance->empresaId, $solicitud, 'solicitud_editada', $alcance->usuario->id, null,
                RegistroActividad::diferencias($antes, $solicitud),
            );
        });

        if (! empty($datos['enviar'])) {
            return $this->enviar($alcance, $solicitud->refresh());
        }

        return $solicitud->refresh()->load(['area', 'habilidades', 'solicitante']);
    }

    public function enviar(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud): SolicitudPersonal
    {
        $this->autorizarEdicion($alcance, $solicitud);

        DB::transaction(function () use ($alcance, $solicitud) {
            $bloqueada = SolicitudPersonal::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $estado = FlujoSolicitud::aplicar($bloqueada->estado, 'enviar');
            $bloqueada->update(['estado' => $estado, 'enviada_at' => now()]);
            $this->actividad->registrar($alcance->empresaId, $bloqueada, 'solicitud_enviada', $alcance->usuario->id);
        });

        return $solicitud->refresh()->load(['area', 'habilidades', 'solicitante']);
    }

    /**
     * Aceptar crea el proceso y sus N plazas UNA sola vez: la fila de la
     * solicitud se bloquea y su estado se revalida dentro de la transacción;
     * además seleccion_procesos.solicitud_id es único.
     */
    public function decidir(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud, string $accion, ?string $motivo, ?int $reclutadorId): SolicitudPersonal
    {
        $alcance->exigir('seleccion.solicitudes.decidir');
        $this->autorizarLectura($alcance, $solicitud);

        DB::transaction(function () use ($alcance, $solicitud, $accion, $motivo, $reclutadorId) {
            $bloqueada = SolicitudPersonal::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $estado = FlujoSolicitud::aplicar($bloqueada->estado, $accion, $motivo);

            $bloqueada->update([
                'estado' => $estado,
                'decision_motivo' => $motivo,
                'decidida_por' => $alcance->usuario->id,
                'decidida_at' => now(),
            ]);

            if ($accion === 'aceptar') {
                $this->procesos->crearDesdeSolicitud($bloqueada, $reclutadorId, $alcance->usuario->id);
            }

            $this->actividad->registrar($alcance->empresaId, $bloqueada, "solicitud_{$estado}", $alcance->usuario->id, $motivo);
        });

        return $solicitud->refresh()->load(['area', 'habilidades', 'solicitante', 'proceso']);
    }

    /**
     * Cuántas candidaturas y plazas afectaría cancelar (se muestra antes de
     * confirmar).
     *
     * @return array{postulaciones_activas: int, plazas_pendientes: int, plazas_cubiertas: int}
     */
    public function impactoCancelacion(SolicitudPersonal $solicitud): array
    {
        $proceso = $solicitud->proceso;
        if (! $proceso) {
            return ['postulaciones_activas' => 0, 'plazas_pendientes' => 0, 'plazas_cubiertas' => 0];
        }

        return [
            'postulaciones_activas' => Postulacion::where('proceso_id', $proceso->id)
                ->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA])->count(),
            'plazas_pendientes' => Plaza::where('proceso_id', $proceso->id)->where('estado', Plaza::DISPONIBLE)->count(),
            'plazas_cubiertas' => Plaza::where('proceso_id', $proceso->id)->where('estado', Plaza::CUBIERTA)->count(),
        ];
    }

    /**
     * Cancelar una solicitud aceptada: cancela plazas pendientes, retira la
     * publicación y finaliza las postulaciones vigentes con causa
     * "proceso_cancelado". Las contrataciones ya realizadas NO se revierten
     * (sus plazas cubiertas y colaboradores quedan intactos).
     *
     * @return array{solicitud: SolicitudPersonal, impacto: array<string, int>}
     */
    public function cancelar(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud, ?string $motivo): array
    {
        $this->autorizarLectura($alcance, $solicitud);

        $esPropia = $solicitud->solicitante_user_id === $alcance->usuario->id;
        $puedeGestionar = $alcance->puede('seleccion.solicitudes.decidir') || $alcance->puede('seleccion.vacantes.gestionar');
        if (! $puedeGestionar && ! ($esPropia && $solicitud->estado !== SolicitudPersonal::ACEPTADA)) {
            throw new AuthorizationException('No puedes cancelar esta solicitud.');
        }

        $impacto = $this->impactoCancelacion($solicitud);

        DB::transaction(function () use ($alcance, $solicitud, $motivo) {
            $bloqueada = SolicitudPersonal::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $estadoAnterior = $bloqueada->estado;
            $estado = FlujoSolicitud::aplicar($estadoAnterior, 'cancelar', $motivo);

            $bloqueada->update([
                'estado' => $estado,
                'cancelacion_motivo' => $motivo,
                'cancelada_por' => $alcance->usuario->id,
                'cancelada_at' => now(),
            ]);

            if ($estadoAnterior === SolicitudPersonal::ACEPTADA && $bloqueada->proceso) {
                $this->procesos->cancelarPorSolicitud($bloqueada->proceso, (string) $motivo, $alcance->usuario->id);
                $this->postulaciones->finalizarPorCancelacion($bloqueada->proceso, $alcance->usuario->id);
            }

            $this->actividad->registrar($alcance->empresaId, $bloqueada, 'solicitud_cancelada', $alcance->usuario->id, $motivo);
        });

        return ['solicitud' => $solicitud->refresh()->load(['area', 'habilidades', 'solicitante', 'proceso']), 'impacto' => $impacto];
    }

    private function autorizarEdicion(AlcanceSeleccion $alcance, SolicitudPersonal $solicitud): void
    {
        $this->autorizarLectura($alcance, $solicitud);

        if ($solicitud->solicitante_user_id !== $alcance->usuario->id && ! $alcance->puede('seleccion.solicitudes.decidir')) {
            throw new AuthorizationException('Solo quien creó la solicitud puede editarla.');
        }

        if (! $solicitud->editable()) {
            throw ValidationException::withMessages(['estado' => 'Solo se editan solicitudes en borrador o devueltas.']);
        }
    }

    private function validarArea(AlcanceSeleccion $alcance, int $areaId): void
    {
        $existe = Area::withoutGlobalScope(EmpresaScope::class)
            ->where('id', $areaId)
            ->where('empresa_id', $alcance->empresaId)
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages(['area_id' => 'El área no pertenece a la empresa activa.']);
        }

        if (! $alcance->puedeVerArea($areaId)) {
            throw new AuthorizationException('Solo puedes solicitar personal para tus áreas autorizadas.');
        }
    }

    /**
     * @param  array<int, array{nombre: string, nivel: int}>  $habilidades
     */
    private function sincronizarHabilidades(SolicitudPersonal $solicitud, array $habilidades): void
    {
        $solicitud->habilidades()->delete();
        foreach ($habilidades as $habilidad) {
            $solicitud->habilidades()->create(['nombre' => trim($habilidad['nombre']), 'nivel' => (int) $habilidad['nivel']]);
        }
    }
}
