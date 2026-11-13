<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Application\EnvioBoletas\EnviarBoletasPorCorreo;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Envío por correo de las boletas pagadas seleccionadas en la planilla. */
class EnvioBoletaController extends Controller
{
    public function __construct(private readonly EnviarBoletasPorCorreo $envios) {}

    /** Resumen previo al envío: a quién se enviará y a quién no, y por qué. */
    public function previsualizar(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate($this->reglas());

        return response()->json(['data' => $this->envios->clasificar(
            $this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'], (bool) ($datos['reenviar'] ?? false),
        )]);
    }

    public function enviar(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate($this->reglas());
        $resultado = $this->envios->enviar(
            $this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'], $request->user('api')->id, (bool) ($datos['reenviar'] ?? false),
        );

        return response()->json(['data' => $resultado], 202);
    }

    private function reglas(): array
    {
        return [
            'boleta_ids' => ['required', 'array', 'min:1', 'max:1000'], 'boleta_ids.*' => ['required', 'integer', 'distinct'],
            // true: también a quienes ya recibieron su boleta (ej. borraron el correo).
            'reenviar' => ['sometimes', 'boolean'],
        ];
    }

    /** La empresa sale del ciclo y se valida contra el usuario, como en BoletaController. */
    private function empresa(Request $request, CicloRemunerativo $ciclo)
    {
        $empresa = $ciclo->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a la empresa de este ciclo remunerativo.');

        return $empresa;
    }
}
