<?php

namespace App\Modules\Nominas\Domain;

/**
 * Política del Bono de Asistencia (Livex, personal comercial) — reglas de
 * la infografía "Criterios del Bono de Asistencia" + condiciones de
 * septiembre 2026. Sin faltas ni tardanzas, no depende de meta comercial ni
 * de infraestructura: 100% puro cálculo, testable sin HTTP ni base de datos.
 *
 * Prioridad cuando coinciden varias incidencias en el mismo mes (no está
 * definida por Gerencia todavía — ver política, sección 6): manda la regla
 * más severa. Una falta injustificada o 3+ tardanzas anulan el bono sin
 * posibilidad de recuperación, sin importar cuántas faltas justificadas
 * también existan ese mes. Livex revisa y puede corregir el resultado al
 * devolver el Excel, así que un valor propuesto aquí nunca es definitivo.
 *
 * $metaComercialCumplida en null representa "todavía no se sabe" (antes de
 * enviar el Excel a Livex): se propone el porcentaje SIN recuperación, el
 * más conservador. Solo con true se aplica la recuperación.
 */
class BonoAsistenciaCalculator
{
    /**
     * @return array{porcentaje: int, monto: float, motivo: string}
     */
    public function calcular(
        int $diasFaltaJustificada,
        int $diasFaltaInjustificada,
        int $tardanzas,
        float $bonoBase,
        ?bool $metaComercialCumplida,
    ): array {
        if ($diasFaltaInjustificada >= 1) {
            return $this->resultado(0, $bonoBase, 'Falta injustificada en el mes: pierde el 100% del bono, no aplica recuperación.');
        }

        if ($tardanzas >= 3) {
            return $this->resultado(0, $bonoBase, '3 o más tardanzas en el mes: pierde el 100% del bono, no aplica recuperación.');
        }

        if ($diasFaltaJustificada >= 2) {
            $recupera = $metaComercialCumplida === true;

            return $this->resultado(
                $recupera ? 50 : 0,
                $bonoBase,
                $recupera
                    ? '2 faltas justificadas: recupera el 50% del bono por cumplir la meta comercial.'
                    : '2 faltas justificadas: pierde el 100% del bono; puede recuperar el 50% si cumple la meta comercial.',
            );
        }

        if ($diasFaltaJustificada === 1) {
            $recupera = $metaComercialCumplida === true;

            return $this->resultado(
                $recupera ? 100 : 50,
                $bonoBase,
                $recupera
                    ? '1 falta justificada: recupera el 100% del bono por cumplir la meta comercial.'
                    : '1 falta justificada: recibe el 50% del bono; recupera el resto si cumple la meta comercial.',
            );
        }

        return $this->resultado(100, $bonoBase, 'Sin faltas y menos de 3 tardanzas: 100% del bono.');
    }

    /**
     * @return array{porcentaje: int, monto: float, motivo: string}
     */
    private function resultado(int $porcentaje, float $bonoBase, string $motivo): array
    {
        return [
            'porcentaje' => $porcentaje,
            'monto' => round($bonoBase * $porcentaje / 100, 2),
            'motivo' => $motivo,
        ];
    }
}
