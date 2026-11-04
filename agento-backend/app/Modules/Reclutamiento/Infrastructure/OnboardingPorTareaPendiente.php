<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use App\Modules\Reclutamiento\Domain\Contracts\IniciadorOnboarding;
use App\Modules\Reclutamiento\Models\Tarea;

/**
 * Agento todavía no tiene un módulo de onboarding. Este adaptador NO lo
 * simula: deja una tarea persistida de seguimiento y responde
 * "pendiente_integracion", que la interfaz muestra como tal. Cuando exista
 * el módulo, se reemplaza el binding en ReclutamientoServiceProvider.
 */
class OnboardingPorTareaPendiente implements IniciadorOnboarding
{
    public function iniciar(int $empresaId, int $colaboradorId, int $postulacionId, ?int $usuarioId): array
    {
        Tarea::create([
            'empresa_id' => $empresaId,
            'tipo' => Tarea::ONBOARDING,
            'titulo' => 'Coordinar onboarding del nuevo colaborador',
            'detalle' => 'Onboarding no integrado en Agento: registrar manualmente inducción, accesos y entrega de equipos.',
            'permiso_responsable' => 'colaboradores.editar',
            'postulacion_id' => $postulacionId,
            'colaborador_id' => $colaboradorId,
            'estado' => 'pendiente',
        ]);

        return [
            'estado' => 'pendiente_integracion',
            'detalle' => 'Onboarding sin integración: se registró una tarea de seguimiento.',
        ];
    }
}
