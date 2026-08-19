<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ConceptoRemuneracionController extends Controller
{
    /**
     * Catálogo único de conceptos — el frontend NUNCA decide si un código es
     * ingreso/egreso o si es remunerativo; solo lo lee de acá para mostrarlo
     * y para elegir el concepto_id correcto al registrar una comisión/bono.
     */
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            ConceptoRemuneracion::where('activo', true)
                ->get(['id', 'codigo', 'nombre', 'tipo', 'es_remunerativo_laboral', 'afecta_renta_5ta']),
        );
    }
}
