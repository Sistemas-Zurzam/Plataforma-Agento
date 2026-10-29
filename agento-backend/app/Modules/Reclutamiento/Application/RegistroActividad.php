<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Models\Actividad;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de Selección: actor, fecha, acción, motivo y cambios. Nunca se
 * registran aquí documentos, respuestas de pruebas ni tokens.
 */
class RegistroActividad
{
    /**
     * @param  array<string, mixed>|null  $cambios
     */
    public function registrar(
        int $empresaId,
        Model $entidad,
        string $accion,
        ?int $actorUserId,
        ?string $motivo = null,
        ?array $cambios = null,
        string $actorTipo = 'usuario',
    ): void {
        Actividad::create([
            'empresa_id' => $empresaId,
            'entidad_tipo' => class_basename($entidad),
            'entidad_id' => $entidad->getKey(),
            'accion' => $accion,
            'actor_user_id' => $actorUserId,
            'actor_tipo' => $actorUserId ? $actorTipo : ($actorTipo === 'usuario' ? 'sistema' : $actorTipo),
            'motivo' => $motivo,
            'cambios' => $cambios,
        ]);
    }

    /**
     * Solo los atributos que realmente cambiaron, sin datos sensibles.
     *
     * @param  array<string, mixed>  $antes
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    public static function diferencias(array $antes, Model $despues, array $ignorar = ['updated_at', 'created_at']): array
    {
        $cambios = [];
        foreach ($despues->getChanges() as $campo => $valor) {
            if (in_array($campo, $ignorar, true)) {
                continue;
            }
            $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
        }

        return $cambios;
    }
}
