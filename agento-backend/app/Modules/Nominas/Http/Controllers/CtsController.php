<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\Cts\EnviarBoletasCtsPorCorreo;
use App\Modules\Nominas\Application\Cts\RegistrarDepositosCts;
use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsPdf;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Depósito de CTS, boleta de CTS (hoja de liquidación) y su envío por correo.
 * La empresa es la activa del usuario, como en BeneficioSocialController; la
 * CTS debe pertenecerle.
 */
class CtsController extends Controller
{
    public function registrarDepositos(Request $request, BeneficioSocial $beneficio, RegistrarDepositosCts $depositos): JsonResponse
    {
        $datos = $request->validate([
            ...$this->reglasSeleccion(),
            'fecha_deposito' => ['required', 'date', 'before_or_equal:today'],
            'entidad' => ['required', 'string', 'max:160'],
            'operacion' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $depositos->registrar(
            $this->empresa($request, $beneficio), $beneficio, $datos['detalle_ids'], $datos['fecha_deposito'], $datos['entidad'], $datos['operacion'], $request->user('api')->id,
        )]);
    }

    public function boleta(Request $request, BeneficioSocialDetalle $detalle, BoletaCtsPdf $pdf): Response
    {
        $beneficio = $detalle->beneficioSocial;
        $this->empresa($request, $beneficio);

        return $this->pdf($pdf->generar($beneficio, collect([$detalle->load('colaborador', 'depositos')])),
            'boleta-cts_'.$beneficio->tipo.'_'.$beneficio->anio.'_'.$detalle->colaborador?->numero_documento.'.pdf');
    }

    public function boletas(Request $request, BeneficioSocial $beneficio, BoletaCtsPdf $pdf): Response
    {
        $datos = $request->validate($this->reglasSeleccion());
        $this->empresa($request, $beneficio);
        $detalles = $beneficio->detalles()->whereIn('id', $datos['detalle_ids'])->with('colaborador', 'depositos')->orderBy('id')->get();
        abort_if($detalles->isEmpty(), 422, 'Selecciona al menos un colaborador de esta CTS.');

        return $this->pdf($pdf->generar($beneficio, $detalles), 'boletas-cts_'.$beneficio->tipo.'_'.$beneficio->anio.'.pdf');
    }

    public function previsualizarEnvio(Request $request, BeneficioSocial $beneficio, EnviarBoletasCtsPorCorreo $envios): JsonResponse
    {
        $datos = $request->validate([...$this->reglasSeleccion(), 'reenviar' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $envios->clasificar($this->empresa($request, $beneficio), $beneficio, $datos['detalle_ids'], (bool) ($datos['reenviar'] ?? false))]);
    }

    public function enviar(Request $request, BeneficioSocial $beneficio, EnviarBoletasCtsPorCorreo $envios): JsonResponse
    {
        $datos = $request->validate([...$this->reglasSeleccion(), 'reenviar' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $envios->enviar(
            $this->empresa($request, $beneficio), $beneficio, $datos['detalle_ids'], $request->user('api')->id, (bool) ($datos['reenviar'] ?? false),
        )], 202);
    }

    private function reglasSeleccion(): array
    {
        return ['detalle_ids' => ['required', 'array', 'min:1', 'max:1000'], 'detalle_ids.*' => ['required', 'integer', 'distinct']];
    }

    /** Empresa activa del usuario; la CTS debe ser suya (si no, 404 sin revelar que existe). */
    private function empresa(Request $request, ?BeneficioSocial $beneficio): Empresa
    {
        $empresa = $request->user('api')->empresa;
        abort_unless($empresa && $request->user('api')->tieneAccesoA($empresa), 404);
        abort_unless($beneficio && (int) $beneficio->empresa_id === (int) $empresa->id && str_starts_with($beneficio->tipo, 'cts_'), 404);

        return $empresa;
    }

    private function pdf(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }
}
