<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Máquina de estados de la solicitud de personal:
 *
 *   borrador ──enviar──▶ enviada ──aceptar──▶ aceptada
 *   devuelta ──enviar──▶    │ ├──devolver──▶ devuelta
 *                           │ └──rechazar──▶ rechazada (final)
 *   borrador/devuelta/enviada/aceptada ──cancelar──▶ cancelada (final)
 */
final class FlujoSolicitud
{
    public const ESTADOS = ['borrador', 'enviada', 'devuelta', 'aceptada', 'rechazada', 'cancelada'];

    private const TRANSICIONES = [
        'enviar' => ['borrador', 'devuelta'],
        'aceptar' => ['enviada'],
        'devolver' => ['enviada'],
        'rechazar' => ['enviada'],
        'cancelar' => ['borrador', 'devuelta', 'enviada', 'aceptada'],
    ];

    private const DESTINO = [
        'enviar' => 'enviada',
        'aceptar' => 'aceptada',
        'devolver' => 'devuelta',
        'rechazar' => 'rechazada',
        'cancelar' => 'cancelada',
    ];

    public static function aplicar(string $estadoActual, string $accion, ?string $motivo = null): string
    {
        if (! isset(self::TRANSICIONES[$accion]) || ! in_array($estadoActual, self::TRANSICIONES[$accion], true)) {
            throw new TransicionInvalidaException("No se puede {$accion} una solicitud en estado \"{$estadoActual}\".");
        }

        if (self::requiereMotivo($estadoActual, $accion) && blank($motivo)) {
            throw new TransicionInvalidaException('Esta acción requiere indicar un motivo.');
        }

        return self::DESTINO[$accion];
    }

    public static function requiereMotivo(string $estadoActual, string $accion): bool
    {
        return in_array($accion, ['devolver', 'rechazar'], true)
            || ($accion === 'cancelar' && $estadoActual === 'aceptada');
    }
}
