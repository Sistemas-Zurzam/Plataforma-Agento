<?php

namespace App\Modules\Reclutamiento\Domain;

/**
 * Estados del proceso de selección. La visibilidad de la publicación
 * (interna/externa/retirada) es independiente: un proceso abierto puede
 * estar publicado solo internamente.
 *
 *   borrador ──abrir──▶ abierto ⇄ en_pausa
 *   abierto/en_pausa ──cerrar──▶ cerrado
 *   abierto/en_pausa ──(todas las plazas no canceladas cubiertas)──▶ cubierto
 *   borrador/abierto/en_pausa/cubierto ──cancelar──▶ cancelado
 */
final class FlujoProceso
{
    public const ESTADOS = ['borrador', 'abierto', 'en_pausa', 'cubierto', 'cerrado', 'cancelado'];

    private const TRANSICIONES = [
        'abrir' => ['borrador'],
        'pausar' => ['abierto'],
        'reanudar' => ['en_pausa'],
        'cerrar' => ['abierto', 'en_pausa'],
        'cubrir' => ['abierto', 'en_pausa'],
        'cancelar' => ['borrador', 'abierto', 'en_pausa', 'cubierto'],
    ];

    private const DESTINO = [
        'abrir' => 'abierto',
        'pausar' => 'en_pausa',
        'reanudar' => 'abierto',
        'cerrar' => 'cerrado',
        'cubrir' => 'cubierto',
        'cancelar' => 'cancelado',
    ];

    public static function aplicar(string $estadoActual, string $accion, ?string $motivo = null): string
    {
        if (! isset(self::TRANSICIONES[$accion]) || ! in_array($estadoActual, self::TRANSICIONES[$accion], true)) {
            throw new TransicionInvalidaException("No se puede {$accion} un proceso en estado \"{$estadoActual}\".");
        }

        if (in_array($accion, ['pausar', 'cerrar', 'cancelar'], true) && blank($motivo)) {
            throw new TransicionInvalidaException('Esta acción requiere indicar un motivo interno.');
        }

        return self::DESTINO[$accion];
    }

    /**
     * Estados en los que el equipo puede seguir moviendo candidatos.
     */
    public static function admiteGestionDeCandidatos(string $estado): bool
    {
        return in_array($estado, ['abierto', 'en_pausa'], true);
    }
}
