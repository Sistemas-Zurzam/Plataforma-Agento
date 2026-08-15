<?php

namespace App\Modules\Configuracion\Services;

use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\ComisionAfp;
use Illuminate\Auth\Access\AuthorizationException;

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
    public function crear(array $datos): ComisionAfp
    {
        return ComisionAfp::create($datos);
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
        $masReciente = ComisionAfp::where('afp_id', $comision->afp_id)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $masReciente || $masReciente->id !== $comision->id) {
            throw new AuthorizationException('Solo se puede eliminar el registro vigente más reciente de la AFP.');
        }

        $comision->delete();
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
