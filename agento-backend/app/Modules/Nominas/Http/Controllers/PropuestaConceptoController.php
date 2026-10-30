<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Application\PropuestaConceptoService;
use App\Modules\Nominas\Http\Resources\PropuestaConceptoResource;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PropuestaConcepto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Bandeja de RR. HH. para propuestas de bono/comisión del Portal de
 * Empresa. Empresa activa del usuario autenticado; nunca del request.
 */
class PropuestaConceptoController extends Controller
{
    public function __construct(private readonly PropuestaConceptoService $propuestas) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([PropuestaConcepto::ESTADO_PROPUESTO, PropuestaConcepto::ESTADO_VALIDADO, PropuestaConcepto::ESTADO_RECHAZADO])],
            'ciclo_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PropuestaConcepto::query()->where('empresa_id', $request->user('api')->empresa_id);

        return PropuestaConceptoResource::collection($this->propuestas->listar($query, $filtros, $filtros['per_page'] ?? 20));
    }

    /**
     * Mismas reglas de concepto que el registro manual del ciclo
     * (CicloRemunerativoController::registrarConcepto): BONIFICACION y
     * BONO_NO_REMUNERATIVO exigen la definición PLAME concreta.
     */
    public function resolver(Request $request, int $propuesta): PropuestaConceptoResource
    {
        $empresa = $request->user('api')->empresa;
        $registro = PropuestaConcepto::query()->where('empresa_id', $empresa->id)->whereKey($propuesta)->firstOrFail();

        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);
        $datos = $request->validate([
            'accion' => ['required', Rule::in(['validar', 'rechazar'])],
            'concepto_id' => ['required_if:accion,validar', 'nullable', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $request->input('accion') === 'validar' && $requiereDefinicion ? 'required' : 'prohibited',
                'nullable', 'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'observacion' => ['required_if:accion,rechazar', 'nullable', 'string', 'max:500'],
        ]);

        $resultado = $datos['accion'] === 'validar'
            ? $this->propuestas->validar($empresa, $registro, $datos, $request->user('api'))
            : $this->propuestas->rechazar($empresa, $registro, $datos['observacion'], $request->user('api'));

        return new PropuestaConceptoResource($resultado);
    }
}
