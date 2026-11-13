<?php

namespace App\Modules\Auditoria\Support;

use App\Modules\Auditoria\Models\AuditoriaEvento;
use Illuminate\Database\Eloquent\Model;

/**
 * Acumula, durante una request, los cambios que Eloquent aplica a los
 * modelos (antes/después) para adjuntarlos al evento de esa request.
 * Se registra como "scoped": se reinicia en cada request.
 */
class AuditoriaContexto
{
    private bool $activo = false;

    /** @var list<array<string, mixed>> */
    private array $cambios = [];

    private int $omitidos = 0;

    public function __construct(private readonly SanitizadorAuditoria $sanitizador) {}

    public function activar(): void
    {
        $this->activo = true;
        $this->cambios = [];
        $this->omitidos = 0;
    }

    public function desactivar(): void
    {
        $this->activo = false;
    }

    public function registrar(string $evento, Model $modelo): void
    {
        if (! $this->activo || $modelo instanceof AuditoriaEvento) {
            return;
        }

        $antes = [];
        $despues = [];

        if ($evento === 'updated') {
            foreach ($modelo->getChanges() as $campo => $nuevo) {
                if ($campo === $modelo->getUpdatedAtColumn()) {
                    continue;
                }
                $antes[$campo] = $this->sanitizador->valor($campo, $modelo->getRawOriginal($campo));
                $despues[$campo] = $this->sanitizador->valor($campo, $nuevo);
            }

            if ($despues === []) {
                return;
            }
        } elseif ($evento === 'created') {
            foreach ($modelo->getAttributes() as $campo => $valor) {
                $despues[$campo] = $this->sanitizador->valor($campo, $valor);
            }
        } else {
            foreach ($modelo->getAttributes() as $campo => $valor) {
                $antes[$campo] = $this->sanitizador->valor($campo, $valor);
            }
        }

        if (count($this->cambios) >= (int) config('auditoria.max_cambios_por_request')) {
            $this->omitidos++;

            return;
        }

        $this->cambios[] = [
            'modelo' => class_basename($modelo),
            'id' => $modelo->getKey(),
            'evento' => $evento,
            'antes' => $antes ?: null,
            'despues' => $despues ?: null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cambios(): ?array
    {
        if ($this->cambios === []) {
            return null;
        }

        return ['registros' => $this->cambios, 'omitidos' => $this->omitidos];
    }
}
