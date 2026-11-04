<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\CalculadoraPuntajeFinal;
use App\Modules\Reclutamiento\Models\EntrevistaEvaluacion;
use App\Modules\Reclutamiento\Models\PerfilProceso;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PuntajeFinal;

/**
 * Recalcula y AGREGA una fila de puntaje final con los pesos vigentes del
 * proceso. Las filas anteriores se conservan: cambiar pesos o perfil no
 * reescribe resultados históricos.
 */
class PuntajeFinalService
{
    public function __construct(private readonly CalculadoraPuntajeFinal $calculadora) {}

    public function recalcular(Postulacion $postulacion): PuntajeFinal
    {
        $perfil = PerfilProceso::where('proceso_id', $postulacion->proceso_id)->orderByDesc('version')->firstOrFail();
        $componentes = [
            'ats' => $this->componenteAts($postulacion),
            'psicometria' => $this->componentePsicometria($postulacion),
            'entrevistas' => $this->componenteEntrevistas($postulacion),
        ];

        $resultado = $this->calculadora->calcular($perfil->pesos(), $componentes);
        $anterior = PuntajeFinal::where('postulacion_id', $postulacion->id)->latest('id')->first();

        if ($anterior
            && $anterior->perfil_version === $perfil->version
            && collect($anterior->componentes)->except('_pesos_efectivos')->all() == $componentes
            && $anterior->pesos == $perfil->pesos()) {
            return $anterior;
        }

        return PuntajeFinal::create([
            'empresa_id' => $postulacion->empresa_id,
            'postulacion_id' => $postulacion->id,
            'perfil_version' => $perfil->version,
            'pesos' => $perfil->pesos(),
            'componentes' => [...$componentes, '_pesos_efectivos' => $resultado['pesos_efectivos']],
            'puntaje' => $resultado['puntaje'],
            'provisional' => $resultado['provisional'],
        ]);
    }

    /**
     * ATS completo solo con una evaluación concluyente. "requiere_revision" o
     * un error del proveedor dejan el componente pendiente (nunca en 0).
     *
     * @return array{estado: string, valor: float|null}
     */
    private function componenteAts(Postulacion $postulacion): array
    {
        if ($postulacion->no_aplica_ats) {
            return ['estado' => 'no_aplica', 'valor' => null];
        }

        $ultima = $postulacion->evaluacionesAts()->first();
        if ($ultima && in_array($ultima->resultado, ['apto', 'bajo_umbral', 'no_apto'], true) && $ultima->puntaje !== null) {
            return ['estado' => 'completo', 'valor' => (float) $ultima->puntaje];
        }

        return ['estado' => 'pendiente', 'valor' => null];
    }

    /**
     * Promedio de los intentos cerrados de las pruebas asignadas (no anuladas).
     * Mientras alguna prueba asignada no se cierre, queda pendiente.
     *
     * @return array{estado: string, valor: float|null}
     */
    private function componentePsicometria(Postulacion $postulacion): array
    {
        if ($postulacion->no_aplica_psicometria) {
            return ['estado' => 'no_aplica', 'valor' => null];
        }

        $asignaciones = PruebaAsignacion::where('postulacion_id', $postulacion->id)
            ->where('estado', '!=', PruebaAsignacion::ANULADA)
            ->with('intento')
            ->get();

        $puntajes = $asignaciones->map(fn (PruebaAsignacion $a) => $a->intento?->finalizado_at ? $a->intento->puntaje : null);
        if ($asignaciones->isEmpty() || $puntajes->contains(null)) {
            return ['estado' => 'pendiente', 'valor' => null];
        }

        return ['estado' => 'completo', 'valor' => round((float) $puntajes->avg(), 2)];
    }

    /**
     * Promedio de las evaluaciones registradas en entrevistas no canceladas.
     * Pendiente si no hay evaluaciones o si queda alguna entrevista sin evaluar.
     *
     * @return array{estado: string, valor: float|null}
     */
    private function componenteEntrevistas(Postulacion $postulacion): array
    {
        if ($postulacion->no_aplica_entrevistas) {
            return ['estado' => 'no_aplica', 'valor' => null];
        }

        $entrevistas = $postulacion->entrevistas()->where('estado', '!=', 'cancelada')->withCount('evaluaciones')->get();
        $evaluaciones = EntrevistaEvaluacion::whereIn('entrevista_id', $entrevistas->pluck('id'))->pluck('puntaje');

        if ($evaluaciones->isEmpty() || $entrevistas->contains(fn ($e) => $e->evaluaciones_count === 0)) {
            return ['estado' => 'pendiente', 'valor' => null];
        }

        return ['estado' => 'completo', 'valor' => round((float) $evaluaciones->avg(), 2)];
    }
}
