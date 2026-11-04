<?php

namespace App\Modules\Personas\Application;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorPeriodoPrueba;
use Illuminate\Validation\ValidationException;

/**
 * Período de prueba del colaborador (inicio, fin y resultado). La duración
 * la define quien contrata: no se fija ningún plazo legal en código.
 */
class RegistrarPeriodoPrueba
{
    public function registrar(Colaborador $colaborador, string $inicio, string $fin, ?int $usuarioId): ColaboradorPeriodoPrueba
    {
        if ($fin < $inicio) {
            throw ValidationException::withMessages(['fecha_fin_prueba' => 'El fin del período de prueba no puede ser anterior a su inicio.']);
        }

        return ColaboradorPeriodoPrueba::create([
            'empresa_id' => $colaborador->empresa_id,
            'colaborador_id' => $colaborador->id,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'resultado' => 'en_curso',
            'registrado_por' => $usuarioId,
        ]);
    }

    public function registrarResultado(ColaboradorPeriodoPrueba $periodo, string $resultado, ?string $observacion): ColaboradorPeriodoPrueba
    {
        if (! in_array($resultado, ColaboradorPeriodoPrueba::RESULTADOS, true)) {
            throw ValidationException::withMessages(['resultado' => 'Resultado no válido.']);
        }

        $periodo->update(['resultado' => $resultado, 'observacion' => $observacion]);

        return $periodo->refresh();
    }
}
