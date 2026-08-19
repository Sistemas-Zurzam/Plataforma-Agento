<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Afp;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AfpController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Afp::orderBy('nombre')->get(['id', 'clave', 'nombre']));
    }
}
