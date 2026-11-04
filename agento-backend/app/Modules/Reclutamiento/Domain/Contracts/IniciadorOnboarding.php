<?php

namespace App\Modules\Reclutamiento\Domain\Contracts;

/**
 * Integración con onboarding del colaborador contratado. Debe informar con
 * honestidad si el onboarding realmente se inició o solo quedó pendiente.
 */
interface IniciadorOnboarding
{
    /**
     * @return array{estado: 'iniciado'|'pendiente_integracion', detalle: string}
     */
    public function iniciar(int $empresaId, int $colaboradorId, int $postulacionId, ?int $usuarioId): array;
}
