<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\CalificadorPrueba;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Models\IntentoRespuesta;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PruebaIntento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Aplicación de pruebas al candidato.
 *
 * - Acceso por token opaco limitado a UNA asignación (candidato + prueba);
 *   se guarda solo su hash.
 * - El tiempo lo controla el servidor: vence_at se fija al iniciar. Pasado
 *   ese momento no se aceptan respuestas y el intento se cierra.
 * - Un único intento por asignación: reconectarse devuelve el mismo intento
 *   mientras esté vigente (no reinicia el reloj).
 * - Finalizar es idempotente.
 * - Las claves (valor de opciones) y resultados nunca salen por los
 *   endpoints públicos.
 */
class AplicacionPruebaService
{
    public function __construct(
        private readonly CalificadorPrueba $calificador,
        private readonly PuntajeFinalService $puntajes,
        private readonly CorreoReclutamiento $correo,
        private readonly RegistroActividad $actividad,
    ) {}

    /**
     * @return array{asignacion: PruebaAsignacion, enlace: string, correo_enviado: bool}
     */
    public function asignar(AlcanceSeleccion $alcance, Postulacion $postulacion, Prueba $prueba): array
    {
        if ($prueba->empresa_id !== $postulacion->empresa_id || $prueba->estado !== Prueba::PUBLICADA) {
            throw ValidationException::withMessages(['prueba_id' => 'La prueba no está publicada en esta empresa.']);
        }
        if (! FlujoPostulacion::esVigente($postulacion->estado)) {
            throw new TransicionInvalidaException('Solo se asignan pruebas a postulaciones vigentes.');
        }
        if (PruebaAsignacion::where('postulacion_id', $postulacion->id)->where('prueba_id', $prueba->id)->exists()) {
            throw ValidationException::withMessages(['prueba_id' => 'Esta prueba ya fue asignada a la postulación.']);
        }

        $token = Str::random(64);
        $asignacion = DB::transaction(function () use ($alcance, $postulacion, $prueba, $token) {
            $asignacion = PruebaAsignacion::create([
                'empresa_id' => $postulacion->empresa_id,
                'postulacion_id' => $postulacion->id,
                'prueba_id' => $prueba->id,
                'token_hash' => hash('sha256', $token),
                'disponible_hasta' => now()->addDays((int) config('reclutamiento.pruebas.dias_para_iniciar', 5))->endOfDay(),
                'estado' => PruebaAsignacion::PENDIENTE,
                'asignado_por' => $alcance->usuario->id,
            ]);
            $this->actividad->registrar($postulacion->empresa_id, $postulacion, 'prueba_asignada', $alcance->usuario->id, null, [
                'prueba_id' => $prueba->id, 'version' => $prueba->version,
            ]);
            $this->puntajes->recalcular($postulacion);

            return $asignacion;
        });

        $enlace = $this->correo->urlPortal("/empleos/prueba/{$token}");
        $enviado = $this->correo->enviar(
            $postulacion->candidato->email,
            "Evaluación: {$prueba->nombre}",
            "Tienes una evaluación pendiente para tu postulación.\nDuración: {$prueba->tiempo_limite_minutos} minutos desde que la inicies.\n"
            ."Disponible hasta: {$asignacion->disponible_hasta->format('d/m/Y')}\n\nIngresa aquí: {$enlace}",
        );

        return ['asignacion' => $asignacion, 'enlace' => $enlace, 'correo_enviado' => $enviado];
    }

    /**
     * Emite un enlace nuevo (invalida el anterior) para el candidato con
     * correo verificado que la solicita desde "Mis postulaciones".
     */
    public function regenerarToken(PruebaAsignacion $asignacion): string
    {
        if (! in_array($asignacion->estado, [PruebaAsignacion::PENDIENTE, PruebaAsignacion::EN_CURSO], true)) {
            throw new TransicionInvalidaException('La evaluación ya no está disponible.');
        }

        $token = Str::random(64);
        $asignacion->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function porToken(string $token): PruebaAsignacion
    {
        $asignacion = PruebaAsignacion::where('token_hash', hash('sha256', $token))->with(['prueba', 'intento'])->first();
        if (! $asignacion) {
            throw new NotFoundHttpException;
        }

        $this->cerrarSiVencida($asignacion);

        return $asignacion->refresh()->load(['prueba', 'intento']);
    }

    /**
     * Inicia o retoma (reconexión) el único intento de la asignación.
     */
    public function iniciar(string $token): PruebaIntento
    {
        // porToken() ya marcó como vencida una asignación fuera de plazo.
        $asignacion = $this->porToken($token);

        return DB::transaction(function () use ($asignacion) {
            $bloqueada = PruebaAsignacion::whereKey($asignacion->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === PruebaAsignacion::EN_CURSO) {
                return $bloqueada->intento()->firstOrFail();
            }
            if ($bloqueada->estado !== PruebaAsignacion::PENDIENTE || $bloqueada->disponible_hasta->isPast()) {
                throw new TransicionInvalidaException('Esta evaluación ya no está disponible.');
            }

            $prueba = $asignacion->prueba;
            $intento = PruebaIntento::create([
                'empresa_id' => $bloqueada->empresa_id,
                'asignacion_id' => $bloqueada->id,
                'iniciado_at' => now(),
                'vence_at' => now()->addMinutes($prueba->tiempo_limite_minutos),
            ]);
            $bloqueada->update(['estado' => PruebaAsignacion::EN_CURSO]);

            return $intento;
        });
    }

    /**
     * Autoguardado de una respuesta. Rechaza respuestas fuera de plazo y
     * cierra el intento si ya venció.
     */
    public function responder(string $token, int $preguntaId, int $opcionId): array
    {
        $asignacion = $this->porToken($token);

        // El cierre por tiempo se confirma ANTES de rechazar la respuesta (si
        // se lanzara la excepción dentro de la transacción, se revertiría).
        $vencido = DB::transaction(function () use ($asignacion) {
            $intento = PruebaIntento::where('asignacion_id', $asignacion->id)->lockForUpdate()->first();
            if ($intento && ! $intento->finalizado_at && $intento->vence_at->isPast()) {
                $this->cerrar($intento, 'tiempo');

                return true;
            }

            return false;
        });
        if ($vencido) {
            throw new TransicionInvalidaException('El tiempo de la evaluación terminó: no se aceptan más respuestas.');
        }

        return DB::transaction(function () use ($asignacion, $preguntaId, $opcionId) {
            $intento = PruebaIntento::where('asignacion_id', $asignacion->id)->lockForUpdate()->first();

            if (! $intento || $intento->finalizado_at || $intento->vence_at->isPast()) {
                throw new TransicionInvalidaException('La evaluación ya finalizó: no se aceptan más respuestas.');
            }

            $pregunta = $asignacion->prueba->preguntas()->whereKey($preguntaId)->first();
            if (! $pregunta || ! $pregunta->opciones()->whereKey($opcionId)->exists()) {
                throw ValidationException::withMessages(['opcion_id' => 'La opción no corresponde a una pregunta de esta evaluación.']);
            }

            IntentoRespuesta::updateOrCreate(
                ['intento_id' => $intento->id, 'pregunta_id' => $preguntaId],
                ['opcion_id' => $opcionId, 'respondida_at' => now()],
            );

            return ['guardado' => true, 'segundos_restantes' => max(0, now()->diffInSeconds($intento->vence_at, false))];
        });
    }

    /**
     * Idempotente: finalizar un intento ya cerrado devuelve el mismo intento.
     */
    public function finalizar(string $token): PruebaIntento
    {
        $asignacion = $this->porToken($token);

        return DB::transaction(function () use ($asignacion) {
            $intento = PruebaIntento::where('asignacion_id', $asignacion->id)->lockForUpdate()->first();
            if (! $intento) {
                throw new TransicionInvalidaException('La evaluación no fue iniciada.');
            }
            if (! $intento->finalizado_at) {
                $this->cerrar($intento, $intento->vence_at->isPast() ? 'tiempo' : 'candidato');
            }

            return $intento->refresh();
        });
    }

    /**
     * Comando programado: cierra intentos vencidos y asignaciones cuyo plazo
     * para iniciar expiró.
     */
    public function cerrarVencidos(): int
    {
        $cerrados = 0;
        PruebaIntento::whereNull('finalizado_at')->where('vence_at', '<=', now())->pluck('id')
            ->each(function (int $id) use (&$cerrados) {
                DB::transaction(function () use ($id, &$cerrados) {
                    $intento = PruebaIntento::whereKey($id)->lockForUpdate()->first();
                    if ($intento && ! $intento->finalizado_at) {
                        $this->cerrar($intento, 'tiempo');
                        $cerrados++;
                    }
                });
            });

        PruebaAsignacion::withoutGlobalScopes()
            ->where('estado', PruebaAsignacion::PENDIENTE)
            ->where('disponible_hasta', '<', now())
            ->update(['estado' => PruebaAsignacion::VENCIDA]);

        return $cerrados;
    }

    private function cerrarSiVencida(PruebaAsignacion $asignacion): void
    {
        $intento = $asignacion->intento;
        if ($intento && ! $intento->finalizado_at && $intento->vence_at->isPast()) {
            DB::transaction(fn () => $this->cerrar(PruebaIntento::whereKey($intento->id)->lockForUpdate()->first(), 'tiempo'));
        }

        if ($asignacion->estado === PruebaAsignacion::PENDIENTE && $asignacion->disponible_hasta->isPast()) {
            $asignacion->update(['estado' => PruebaAsignacion::VENCIDA]);
        }
    }

    private function cerrar(PruebaIntento $intento, string $cierre): void
    {
        if ($intento->finalizado_at) {
            return;
        }

        $asignacion = PruebaAsignacion::withoutGlobalScopes()->with(['prueba.dimensiones', 'prueba.preguntas.opciones'])->findOrFail($intento->asignacion_id);
        $prueba = $asignacion->prueba;
        $respuestas = $intento->respuestas()->pluck('opcion_id', 'pregunta_id')->all();

        $resultado = $this->calificador->calificar(
            $prueba->dimensiones->map(fn ($d) => ['id' => $d->id, 'peso' => $d->peso])->all(),
            $prueba->preguntas->map(fn ($p) => [
                'id' => $p->id,
                'dimension_id' => $p->dimension_id,
                'peso' => $p->peso,
                'opciones' => $p->opciones->map(fn ($o) => ['id' => $o->id, 'valor' => $o->valor])->all(),
            ])->all(),
            $respuestas,
        );

        $intento->update([
            'finalizado_at' => $cierre === 'tiempo' ? $intento->vence_at : now(),
            'cierre' => $cierre,
            'puntaje' => $resultado['puntaje'],
        ]);
        foreach ($resultado['dimensiones'] as $dimensionId => $puntaje) {
            $intento->resultados()->create(['dimension_id' => $dimensionId, 'puntaje' => $puntaje]);
        }
        $asignacion->update(['estado' => PruebaAsignacion::FINALIZADA]);

        $postulacion = Postulacion::withoutGlobalScopes()->find($asignacion->postulacion_id);
        if ($postulacion) {
            $this->puntajes->recalcular($postulacion);
        }
    }
}
