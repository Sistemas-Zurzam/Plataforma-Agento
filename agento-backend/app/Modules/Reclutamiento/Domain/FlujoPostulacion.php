<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Etapa (dónde está en el embudo) y estado (situación de la candidatura) son
 * independientes. Solo una postulación "activa" cambia de etapa.
 *
 * Reglas:
 * - Avanzar a cualquier etapa posterior está permitido (se pueden omitir
 *   etapas, p. ej. sin psicométrica). Retroceder exige motivo.
 * - seleccionar: solo desde etapa "seleccion" y estado activa.
 * - contratar: solo una postulación seleccionada.
 * - descartar / retirar / finalizar por cancelación: desde activa o seleccionada.
 * - reconsiderar: devuelve una descartada a activa, con motivo.
 */
final class FlujoPostulacion
{
    public const ETAPAS = ['postulacion', 'filtro_cv', 'psicometrica', 'entrevista_virtual', 'entrevista_presencial', 'seleccion'];

    public const ACTIVA = 'activa';

    public const RETIRADA = 'retirada';

    public const DESCARTADA = 'descartada';

    public const SELECCIONADA = 'seleccionada';

    public const CONTRATADA = 'contratada';

    public const FINALIZADA_POR_CANCELACION = 'finalizada_por_cancelacion';

    public const ESTADOS = [self::ACTIVA, self::RETIRADA, self::DESCARTADA, self::SELECCIONADA, self::CONTRATADA, self::FINALIZADA_POR_CANCELACION];

    /**
     * Causas internas de descarte. "proceso_cancelado" se reserva para la
     * finalización automática y no se acepta como descarte manual.
     */
    public const CAUSAS_DESCARTE = [
        'no_cumple_requisitos', 'bajo_puntaje', 'resultado_evaluacion', 'resultado_entrevista',
        'expectativa_salarial', 'no_se_presento', 'otro_candidato_seleccionado', 'otro',
    ];

    public static function mover(string $etapaActual, string $estado, string $etapaDestino, ?string $motivo): void
    {
        if ($estado !== self::ACTIVA) {
            throw new TransicionInvalidaException("Solo se pueden mover postulaciones activas (estado actual: {$estado}).");
        }

        $desde = array_search($etapaActual, self::ETAPAS, true);
        $hasta = array_search($etapaDestino, self::ETAPAS, true);

        if ($hasta === false || $desde === false) {
            throw new TransicionInvalidaException('Etapa no reconocida.');
        }

        if ($desde === $hasta) {
            throw new TransicionInvalidaException('La postulación ya se encuentra en esa etapa.');
        }

        if ($hasta < $desde && blank($motivo)) {
            throw new TransicionInvalidaException('Retroceder de etapa requiere indicar un motivo.');
        }
    }

    public static function seleccionar(string $etapa, string $estado): string
    {
        if ($estado !== self::ACTIVA || $etapa !== 'seleccion') {
            throw new TransicionInvalidaException('Solo se puede seleccionar una postulación activa en la etapa de selección.');
        }

        return self::SELECCIONADA;
    }

    public static function contratar(string $estado): string
    {
        if ($estado !== self::SELECCIONADA) {
            throw new TransicionInvalidaException('Solo se puede contratar una postulación seleccionada.');
        }

        return self::CONTRATADA;
    }

    public static function descartar(string $estado, string $causa, ?string $motivo): string
    {
        self::exigirVigente($estado, 'descartar');

        if (! in_array($causa, self::CAUSAS_DESCARTE, true)) {
            throw new TransicionInvalidaException('Causa de descarte no válida.');
        }

        if (blank($motivo)) {
            throw new TransicionInvalidaException('El descarte requiere un motivo interno.');
        }

        return self::DESCARTADA;
    }

    public static function retirar(string $estado): string
    {
        self::exigirVigente($estado, 'retirar');

        return self::RETIRADA;
    }

    public static function finalizarPorCancelacion(string $estado): string
    {
        self::exigirVigente($estado, 'finalizar');

        return self::FINALIZADA_POR_CANCELACION;
    }

    public static function reconsiderar(string $estado, ?string $motivo): string
    {
        if ($estado !== self::DESCARTADA) {
            throw new TransicionInvalidaException('Solo se puede reconsiderar una postulación descartada.');
        }

        if (blank($motivo)) {
            throw new TransicionInvalidaException('Reconsiderar una postulación requiere un motivo.');
        }

        return self::ACTIVA;
    }

    public static function esVigente(string $estado): bool
    {
        return in_array($estado, [self::ACTIVA, self::SELECCIONADA], true);
    }

    private static function exigirVigente(string $estado, string $accion): void
    {
        if (! self::esVigente($estado)) {
            throw new TransicionInvalidaException("No se puede {$accion} una postulación en estado \"{$estado}\".");
        }
    }
}
