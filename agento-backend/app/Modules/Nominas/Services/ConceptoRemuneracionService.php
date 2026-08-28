<?php

namespace App\Modules\Nominas\Services;

use App\Models\User;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConceptoRemuneracionService
{
    /**
     * Actualiza el código PLAME vigente de un concepto (Tabla 22 de SUNAT) y
     * registra la fila de historial correspondiente — nunca se sobrescribe
     * una fila existente, cada cambio real inserta una nueva (mismo patrón
     * que ComisionAfpService::crear()). La columna viva `codigo_plame` sigue
     * siendo la que lee BoletaService al calcular una boleta.
     */
    public function actualizarCodigoPlame(ConceptoRemuneracion $concepto, array $datos, ?User $usuario = null): ConceptoRemuneracion
    {
        $vigenciaDesde = $datos['vigencia_desde'] ?? now()->toDateString();
        // Tabla 22 exige 4 dígitos con cero inicial ("0121", nunca "121") —
        // se normaliza acá para que el Controller pueda seguir aceptando
        // que un administrador escriba el número de forma natural.
        $codigoPlame = isset($datos['codigo_plame']) ? str_pad($datos['codigo_plame'], 4, '0', STR_PAD_LEFT) : null;
        $descripcionSunat = $datos['descripcion_sunat'] ?? null;

        $ultimaVigencia = $concepto->codigosPlameHistorial()->first();
        if ($ultimaVigencia && $vigenciaDesde < $ultimaVigencia->vigencia_desde->toDateString()) {
            throw ValidationException::withMessages([
                'vigencia_desde' => 'La fecha no puede ser anterior al último registro de historial ('.$ultimaVigencia->vigencia_desde->toDateString().').',
            ]);
        }

        // Nada realmente cambió (mismo código y descripción) — no ensuciar
        // el historial con una fila idéntica a la anterior.
        if ($ultimaVigencia && $ultimaVigencia->codigo_plame === $codigoPlame && $ultimaVigencia->descripcion_sunat === $descripcionSunat) {
            return $concepto;
        }

        DB::transaction(function () use ($concepto, $codigoPlame, $descripcionSunat, $vigenciaDesde, $usuario) {
            $concepto->update(['codigo_plame' => $codigoPlame]);

            $concepto->codigosPlameHistorial()->create([
                'codigo_plame' => $codigoPlame,
                'descripcion_sunat' => $descripcionSunat,
                'vigencia_desde' => $vigenciaDesde,
                'actualizado_por_id' => $usuario?->id,
            ]);
        });

        return $concepto->fresh();
    }

    public function historialCodigoPlame(ConceptoRemuneracion $concepto): Collection
    {
        return $concepto->codigosPlameHistorial()->with('actualizadoPor:id,name')->get();
    }
}
