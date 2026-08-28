<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\ConceptoDefinicionPlameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConceptoDefinicionPlameController extends Controller
{
    public function __construct(private ConceptoDefinicionPlameService $definiciones) {}

    public function index(ConceptoRemuneracion $concepto): JsonResponse
    {
        return response()->json(['data' => $this->definiciones->listarPorConcepto($concepto)]);
    }

    public function store(Request $request, ConceptoRemuneracion $concepto): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            // Tabla 22 exige 4 dígitos con cero inicial ("0301") — se acepta
            // 1-4 dígitos aquí y el Service normaliza antes de guardar.
            'codigo_plame' => ['required', 'digits_between:1,4'],
            'descripcion_sunat' => ['nullable', 'string', 'max:255'],
        ]);

        $definicion = $this->definiciones->crear($concepto, $datos, $request->user('api'));

        return response()->json(['data' => $definicion], 201);
    }

    public function update(Request $request, ConceptoDefinicionPlame $definicion): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'codigo_plame' => ['sometimes', 'digits_between:1,4'],
            'descripcion_sunat' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $definicion = $this->definiciones->actualizar($definicion, $datos);

        return response()->json(['data' => $definicion]);
    }
}
