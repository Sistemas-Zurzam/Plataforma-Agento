<?php

namespace App\Modules\Reclutamiento\Application;

use App\Models\User;
use App\Modules\Reclutamiento\Domain\CalculadoraPuntajeFinal;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\Entrevista;
use App\Modules\Reclutamiento\Models\EntrevistaEvaluacion;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntrevistaService
{
    public function __construct(
        private readonly PuntajeFinalService $puntajes,
        private readonly RegistroActividad $actividad,
    ) {}

    public function programar(AlcanceSeleccion $alcance, Postulacion $postulacion, array $datos): Entrevista
    {
        if (! FlujoPostulacion::esVigente($postulacion->estado)) {
            throw new TransicionInvalidaException('Solo se programan entrevistas para postulaciones vigentes.');
        }

        if (! empty($datos['evaluador_user_id'])) {
            $evaluador = User::find($datos['evaluador_user_id']);
            $pertenece = $evaluador && ($evaluador->esAdministradorGlobal() || $evaluador->empresas()->where('empresas.id', $postulacion->empresa_id)->exists());
            if (! $pertenece) {
                throw ValidationException::withMessages(['evaluador_user_id' => 'El evaluador no pertenece a la empresa.']);
            }
        }

        return DB::transaction(function () use ($alcance, $postulacion, $datos) {
            $entrevista = Entrevista::create([
                'empresa_id' => $postulacion->empresa_id,
                'postulacion_id' => $postulacion->id,
                'tipo' => $datos['tipo'],
                'programada_at' => $datos['programada_at'] ?? null,
                'lugar' => $datos['lugar'] ?? null,
                'evaluador_user_id' => $datos['evaluador_user_id'] ?? $alcance->usuario->id,
                'estado' => 'programada',
                'creado_por' => $alcance->usuario->id,
            ]);
            $this->actividad->registrar($postulacion->empresa_id, $postulacion, 'entrevista_programada', $alcance->usuario->id, null, [
                'entrevista_id' => $entrevista->id, 'tipo' => $entrevista->tipo,
            ]);
            $this->puntajes->recalcular($postulacion);

            return $entrevista->load('evaluador:id,name');
        });
    }

    public function cancelar(AlcanceSeleccion $alcance, Entrevista $entrevista, string $motivo): Entrevista
    {
        if ($entrevista->estado !== 'programada') {
            throw new TransicionInvalidaException('Solo se cancelan entrevistas programadas.');
        }

        DB::transaction(function () use ($alcance, $entrevista, $motivo) {
            $entrevista->update(['estado' => 'cancelada']);
            $this->actividad->registrar($entrevista->empresa_id, $entrevista->postulacion, 'entrevista_cancelada', $alcance->usuario->id, $motivo, [
                'entrevista_id' => $entrevista->id,
            ]);
            $this->puntajes->recalcular($entrevista->postulacion);
        });

        return $entrevista->refresh();
    }

    /**
     * Registra la evaluación de UN evaluador (se conservan todas con su
     * autor). Puede evaluar quien gestiona evaluaciones o la persona
     * asignada como evaluador de esa entrevista (p. ej. gerencia).
     */
    public function evaluar(AlcanceSeleccion $alcance, Entrevista $entrevista, array $datos): EntrevistaEvaluacion
    {
        $esEvaluadorAsignado = $entrevista->evaluador_user_id === $alcance->usuario->id;
        if (! $esEvaluadorAsignado && ! $alcance->puede('seleccion.evaluaciones.gestionar')) {
            throw new AuthorizationException('Solo el evaluador asignado o RR. HH. pueden evaluar esta entrevista.');
        }

        if ($entrevista->estado === 'cancelada') {
            throw new TransicionInvalidaException('No se puede evaluar una entrevista cancelada.');
        }

        if ($entrevista->evaluaciones()->where('evaluador_user_id', $alcance->usuario->id)->exists()) {
            throw new TransicionInvalidaException('Ya registraste tu evaluación de esta entrevista.');
        }

        $calificaciones = collect($datos['calificaciones'])->pluck('calificacion')->map(fn ($c) => (int) $c)->all();
        ['promedio' => $promedio, 'puntaje' => $puntaje] = CalculadoraPuntajeFinal::normalizarEntrevista($calificaciones);

        return DB::transaction(function () use ($alcance, $entrevista, $datos, $promedio, $puntaje) {
            $evaluacion = EntrevistaEvaluacion::create([
                'empresa_id' => $entrevista->empresa_id,
                'entrevista_id' => $entrevista->id,
                'evaluador_user_id' => $alcance->usuario->id,
                'fortalezas' => $datos['fortalezas'],
                'riesgos' => $datos['riesgos'],
                'recomendacion' => $datos['recomendacion'],
                'comentarios' => $datos['comentarios'] ?? null,
                'promedio' => $promedio,
                'puntaje' => $puntaje,
            ]);

            foreach ($datos['calificaciones'] as $calificacion) {
                $evaluacion->calificaciones()->create([
                    'competencia' => trim($calificacion['competencia']),
                    'calificacion' => (int) $calificacion['calificacion'],
                ]);
            }

            $entrevista->update(['estado' => 'realizada']);
            $this->actividad->registrar($entrevista->empresa_id, $entrevista->postulacion, 'entrevista_evaluada', $alcance->usuario->id, null, [
                'entrevista_id' => $entrevista->id, 'puntaje' => $puntaje,
            ]);
            $this->puntajes->recalcular($entrevista->postulacion);

            return $evaluacion->load(['calificaciones', 'evaluador:id,name']);
        });
    }
}
