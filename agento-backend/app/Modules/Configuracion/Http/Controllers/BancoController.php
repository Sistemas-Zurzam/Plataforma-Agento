<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Banco;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class BancoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Banco::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']));
    }
}
