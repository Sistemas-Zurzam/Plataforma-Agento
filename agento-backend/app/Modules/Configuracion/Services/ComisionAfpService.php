<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\ComisionAfp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ComisionAfpService
{
    /**
     * Tasas de referencia SBS para "Cargar SBS 2024". No son fuente legal
     * autoritativa: son un punto de partida editable que el admin debe
     * verificar y ajustar según las tasas vigentes publicadas por la SBS.
     */
    private const VALORES_SBS_2024 = [
        'prima' => ['comision_flujo_porcentaje' => 1.60],
        'profuturo' => ['comision_flujo_porcentaje' => 1.69],
        'integra' => ['comision_flujo_porcentaje' => 1.55],
        'habitat' => ['comision_flujo_porcentaje' => 1.47],
    ];

    private const APORTE_OBLIGATORIO_PORCENTAJE = 10.00;

    private const PRIMA_SEGURO_PORCENTAJE = 1.37;

    private const SOBRE_SALDO_ANUAL_PORCENTAJE = 1.00;

    private const COMISION_MIXTA_PORCENTAJE = 0.00;

    /**
     * Para cada AFP, la comisión vigente (mayor vigencia_desde, empate por
     * id) con los totales calculados. "Total" es derivado, no se guarda
     * como columna (evita datos derivados almacenados sin necesidad).
     *
     * @return array{afps: array<int, array>}
     */
    public function listar(): array
    {
        $comisionesVigentesPorAfp = ComisionAfp::orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->get()
            ->groupBy('afp_id')
            ->map(fn ($grupo) => $grupo->first());

        $afps = Afp::orderBy('id')->get()->map(function (Afp $afp) use ($comisionesVigentesPorAfp) {
            $comision = $comisionesVigentesPorAfp->get($afp->id);

            return [
                'afp_id' => $afp->id,
                'clave' => $afp->clave,
                'nombre' => $afp->nombre,
                'comision_id' => $comision?->id,
                'vigencia_desde' => $comision?->vigencia_desde?->toDateString(),
                'aporte_obligatorio_porcentaje' => $comision?->aporte_obligatorio_porcentaje,
                'prima_seguro_porcentaje' => $comision?->prima_seguro_porcentaje,
                'comision_flujo_porcentaje' => $comision?->comision_flujo_porcentaje,
                'comision_mixta_porcentaje' => $comision?->comision_mixta_porcentaje,
                'sobre_saldo_anual_porcentaje' => $comision?->sobre_saldo_anual_porcentaje,
                'total_flujo_porcentaje' => $comision
                    ? round($comision->aporte_obligatorio_porcentaje + $comision->prima_seguro_porcentaje + $comision->comision_flujo_porcentaje, 2)
                    : null,
                'total_mixta_porcentaje' => $comision
                    ? round($comision->aporte_obligatorio_porcentaje + $comision->prima_seguro_porcentaje + $comision->comision_mixta_porcentaje, 2)
                    : null,
            ];
        })->values();

        return ['afps' => $afps];
    }

    /**
     * Registra una nueva comisión vigente. Nunca actualiza ni borra una
     * fila existente: el historial se preserva insertando siempre una fila
     * nueva (regla de auditoría de CLAUDE.md).
     */
    public function crear(array $datos, ?User $usuario = null): ComisionAfp
    {
        return ComisionAfp::create([
            ...$datos,
            'creado_por_id' => $usuario?->id,
        ]);
    }

    /**
     * Corrige el registro vigente más reciente de una AFP (p. ej. un valor
     * mal digitado al crearlo). No es "nueva vigencia": no crea una fila,
     * modifica la existente — por eso solo se permite sobre la más
     * reciente, la misma que `eliminar()` protege, y exige `motivo` para
     * dejar constancia de que fue una corrección y no un evento legal
     * nuevo (ver CLAUDE.md "Corrección vs Nueva vigencia").
     *
     * @throws AuthorizationException si la comisión no es la más reciente de su AFP.
     * @throws ValidationException si la nueva vigencia_desde queda antes de la comisión previa.
     */
    public function actualizar(ComisionAfp $comision, array $datos, ?User $usuario = null): ComisionAfp
    {
        $this->asegurarEsLaMasReciente($comision, 'Solo se puede editar el registro vigente más reciente de la AFP.');

        $anterior = ComisionAfp::where('afp_id', $comision->afp_id)
            ->where('id', '!=', $comision->id)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if ($anterior && $datos['vigencia_desde'] < $anterior->vigencia_desde->toDateString()) {
            throw ValidationException::withMessages([
                'vigencia_desde' => 'La fecha no puede ser anterior a la vigencia previa registrada ('.$anterior->vigencia_desde->toDateString().').',
            ]);
        }

        $comision->update([
            ...$datos,
            'creado_por_id' => $usuario?->id,
        ]);

        return $comision->fresh();
    }

    /**
     * Solo permite eliminar la comisión vigente más reciente de su AFP
     * (mayor vigencia_desde, empate por id) — no se puede borrar una
     * comisión ya superada por una vigencia posterior, para no perder
     * trazabilidad del historial.
     *
     * @throws AuthorizationException si la comisión no es la más reciente de su AFP.
     */
    public function eliminar(ComisionAfp $comision): void
    {
        $this->asegurarEsLaMasReciente($comision, 'Solo se puede eliminar el registro vigente más reciente de la AFP.');

        $comision->delete();
    }

    /**
     * Historial completo (todas las vigencias, más reciente primero) de
     * comisiones registradas para una AFP.
     *
     * @return array<int, array>
     */
    public function historial(Afp $afp): array
    {
        return ComisionAfp::where('afp_id', $afp->id)
            ->with('creadoPor:id,name')
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ComisionAfp $comision) => [
                'id' => $comision->id,
                'vigencia_desde' => $comision->vigencia_desde->toDateString(),
                'aporte_obligatorio_porcentaje' => $comision->aporte_obligatorio_porcentaje,
                'prima_seguro_porcentaje' => $comision->prima_seguro_porcentaje,
                'comision_flujo_porcentaje' => $comision->comision_flujo_porcentaje,
                'comision_mixta_porcentaje' => $comision->comision_mixta_porcentaje,
                'sobre_saldo_anual_porcentaje' => $comision->sobre_saldo_anual_porcentaje,
                'motivo' => $comision->motivo,
                'creado_por' => $comision->creadoPor?->name,
                'creado_en' => $comision->created_at->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @throws AuthorizationException si `$comision` no es la más reciente de su AFP.
     */
    private function asegurarEsLaMasReciente(ComisionAfp $comision, string $mensaje): void
    {
        $masReciente = ComisionAfp::where('afp_id', $comision->afp_id)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $masReciente || $masReciente->id !== $comision->id) {
            throw new AuthorizationException($mensaje);
        }
    }

    /**
     * Completa únicamente las AFP que todavía no tienen ninguna comisión
     * registrada. Idempotente: no toca AFP que ya tengan al menos una.
     */
    public function cargarValoresSbs2024(): void
    {
        $afpsConComision = ComisionAfp::pluck('afp_id')->unique();
        $hoy = now()->toDateString();

        Afp::whereNotIn('id', $afpsConComision)
            ->get()
            ->each(function (Afp $afp) use ($hoy) {
                $valoresPorDefecto = self::VALORES_SBS_2024[$afp->clave] ?? null;

                if (! $valoresPorDefecto) {
                    return;
                }

                ComisionAfp::create([
                    'afp_id' => $afp->id,
                    'vigencia_desde' => $hoy,
                    'aporte_obligatorio_porcentaje' => self::APORTE_OBLIGATORIO_PORCENTAJE,
                    'prima_seguro_porcentaje' => self::PRIMA_SEGURO_PORCENTAJE,
                    'comision_flujo_porcentaje' => $valoresPorDefecto['comision_flujo_porcentaje'],
                    'comision_mixta_porcentaje' => self::COMISION_MIXTA_PORCENTAJE,
                    'sobre_saldo_anual_porcentaje' => self::SOBRE_SALDO_ANUAL_PORCENTAJE,
                ]);
            });
    }
}
