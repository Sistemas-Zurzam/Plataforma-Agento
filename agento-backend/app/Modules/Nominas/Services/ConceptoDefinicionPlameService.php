<?php

namespace App\Modules\Nominas\Services;

use App\Models\User;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Rangos de código PLAME (Tabla 22, Anexo 2) confirmados como válidos para
 * cada concepto motor demasiado genérico — evita que se guarde un código
 * arbitrario (ej. "9999") que no pertenezca al catálogo oficial real.
 */
class ConceptoDefinicionPlameService
{
    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private const RANGOS_VALIDOS = [
        // Tabla 22: 301-314 son los códigos de bonificación (por años de
        // servicio, cierre de pliego, producción, turno nocturno, etc.).
        'BONIFICACION' => [301, 314],
        // Tabla 22: 1001-1020 son "Otros Conceptos", de libre definición
        // por el empleador junto con su descripción.
        'BONO_NO_REMUNERATIVO' => [1001, 1020],
    ];

    public function listarPorConcepto(ConceptoRemuneracion $concepto): Collection
    {
        return ConceptoDefinicionPlame::where('concepto_remuneracion_id', $concepto->id)->orderBy('nombre')->get();
    }

    public function crear(ConceptoRemuneracion $concepto, array $datos, ?User $usuario = null): ConceptoDefinicionPlame
    {
        // Tabla 22 exige 4 dígitos con cero inicial — un administrador
        // escribe "301" de forma natural, se normaliza acá a "0301".
        $codigoPlame = str_pad($datos['codigo_plame'], 4, '0', STR_PAD_LEFT);
        $this->validarCodigoEnRango($concepto, $codigoPlame);

        return ConceptoDefinicionPlame::create([
            'concepto_remuneracion_id' => $concepto->id,
            'nombre' => $datos['nombre'],
            'codigo_plame' => $codigoPlame,
            'descripcion_sunat' => $datos['descripcion_sunat'] ?? null,
            'creado_por' => $usuario?->id,
        ]);
    }

    public function actualizar(ConceptoDefinicionPlame $definicion, array $datos): ConceptoDefinicionPlame
    {
        $codigoPlame = $definicion->codigo_plame;
        if (array_key_exists('codigo_plame', $datos)) {
            $codigoPlame = str_pad($datos['codigo_plame'], 4, '0', STR_PAD_LEFT);
            $this->validarCodigoEnRango($definicion->concepto, $codigoPlame);
        }

        $definicion->update([
            'nombre' => $datos['nombre'] ?? $definicion->nombre,
            'codigo_plame' => $codigoPlame,
            'descripcion_sunat' => $datos['descripcion_sunat'] ?? $definicion->descripcion_sunat,
            'activo' => $datos['activo'] ?? $definicion->activo,
        ]);

        return $definicion->fresh();
    }

    /**
     * @throws ValidationException si el concepto motor no tiene un rango
     *   oficial confirmado, o si el código no cae dentro de ese rango.
     */
    private function validarCodigoEnRango(ConceptoRemuneracion $concepto, string $codigoPlame): void
    {
        $rango = self::RANGOS_VALIDOS[$concepto->codigo] ?? null;

        if (! $rango) {
            throw ValidationException::withMessages([
                'codigo_plame' => "El concepto {$concepto->codigo} no tiene un rango de Tabla 22 confirmado para definiciones específicas.",
            ]);
        }

        $numero = (int) $codigoPlame;

        if ($numero < $rango[0] || $numero > $rango[1]) {
            throw ValidationException::withMessages([
                'codigo_plame' => "El código debe estar entre {$rango[0]} y {$rango[1]} (Tabla 22, Anexo 2) para este concepto.",
            ]);
        }
    }
}
