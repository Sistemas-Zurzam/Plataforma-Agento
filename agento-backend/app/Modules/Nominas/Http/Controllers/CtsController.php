<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\Cts\EnviarBoletasCtsPorCorreo;
use App\Modules\Nominas\Application\Cts\ExportarTxtCtsBbva;
use App\Modules\Nominas\Application\Cts\ImportarCtsHistoricaExcel;
use App\Modules\Nominas\Application\Cts\RegistrarDepositosCts;
use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsPdf;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Services\BeneficioSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

    /** Resumen previo del TXT de BBVA: quiénes entran, quiénes no y por qué, total y cuenta de cargo (enmascarada). */
    public function validarTxtBbva(Request $request, BeneficioSocial $beneficio, ExportarTxtCtsBbva $txt): JsonResponse
    {
        $datos = $request->validate($this->reglasSeleccion());

        return response()->json(['data' => $txt->validar($this->empresa($request, $beneficio), $beneficio, $datos['detalle_ids'])]);
    }

    /** Descarga el TXT de BBVA. No registra el depósito: eso se hace con el N.º de operación del banco. */
    public function exportarTxtBbva(Request $request, BeneficioSocial $beneficio, ExportarTxtCtsBbva $txt): Response
    {
        $datos = $request->validate($this->reglasSeleccion());
        $empresa = $this->empresa($request, $beneficio);
        $archivo = $txt->exportar($empresa, $beneficio, $datos['detalle_ids']);

        // Auditoría sin cuentas, documentos ni contenido del archivo.
        Log::info('cts.bbva_txt.export', ['usuario_id' => $request->user('api')->id, 'empresa_id' => $empresa->id, 'beneficio_id' => $beneficio->id]);

        return response($archivo['contenido'], 200, [
            'Content-Type' => 'text/plain; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Content-Length' => (string) strlen($archivo['contenido']),
        ]);
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

    /** Vista previa de la CTS cargada desde el Excel de RR.HH.: filas reconocidas, errores y total. No guarda nada. */
    public function previsualizarExcel(Request $request, ImportarCtsHistoricaExcel $importacion): JsonResponse
    {
        $this->soloAdministrador($request);
        $datos = $request->validate($this->reglasExcel());

        $vista = $importacion->previsualizar($this->empresaActiva($request), $datos['tipo'], (int) $datos['anio'], $datos['archivo']->getRealPath());
        // El número de cuenta CTS no viaja al navegador en la vista previa.
        $vista['filas'] = array_map(fn (array $f) => Arr::except($f, ['cuenta_cts_nueva']), $vista['filas']);

        return response()->json(['data' => $vista]);
    }

    /** Guarda la CTS del Excel como versión vigente (origen 'excel') y devuelve el resumen actualizado. */
    public function importarExcel(Request $request, ImportarCtsHistoricaExcel $importacion, BeneficioSocialService $beneficios): JsonResponse
    {
        $this->soloAdministrador($request);
        $datos = $request->validate($this->reglasExcel());
        $empresa = $this->empresaActiva($request);
        $beneficio = $importacion->importar($empresa, $datos['tipo'], (int) $datos['anio'], $datos['archivo']->getRealPath(), $datos['archivo']->getClientOriginalName(), $request->user('api')->id);

        Log::info('cts.excel_historico.import', ['usuario_id' => $request->user('api')->id, 'empresa_id' => $empresa->id, 'beneficio_id' => $beneficio->id]);

        return response()->json($beneficios->resumen($empresa, $datos['tipo'], (int) $datos['anio']));
    }

    /** La carga de una CTS desde Excel reemplaza el cálculo de Agento: solo la hace un administrador. */
    private function soloAdministrador(Request $request): void
    {
        abort_unless($request->user('api')?->esAdministradorGlobal(), 403, 'Solo un administrador puede cargar la CTS desde Excel.');
    }

    private function reglasExcel(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'tipo' => ['required', Rule::in(['cts_mayo', 'cts_noviembre'])],
            'anio' => ['required', 'integer', 'between:2020,2100'],
        ];
    }

    private function empresaActiva(Request $request): Empresa
    {
        $empresa = $request->user('api')->empresa;
        abort_unless($empresa && $request->user('api')->tieneAccesoA($empresa), 404);

        return $empresa;
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
