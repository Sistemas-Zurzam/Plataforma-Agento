<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreEmpresaCuentaBancariaRequest;
use App\Modules\Configuracion\Http\Requests\UpdateEmpresaCuentaBancariaRequest;
use App\Modules\Configuracion\Http\Resources\EmpresaCuentaBancariaResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Services\EmpresaCuentaBancariaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmpresaCuentaBancariaController extends Controller
{
    public function __construct(private readonly EmpresaCuentaBancariaService $cuentas) {}

    public function index(Request $request, Empresa $empresa): AnonymousResourceCollection
    {
        return EmpresaCuentaBancariaResource::collection(
            $this->cuentas->listar($empresa, $request->user('api')),
        );
    }

    public function store(StoreEmpresaCuentaBancariaRequest $request, Empresa $empresa): EmpresaCuentaBancariaResource
    {
        return new EmpresaCuentaBancariaResource(
            $this->cuentas->crear($empresa, $request->validated(), $request->user('api')),
        );
    }

    public function update(UpdateEmpresaCuentaBancariaRequest $request, Empresa $empresa, EmpresaCuentaBancaria $cuenta): EmpresaCuentaBancariaResource
    {
        return new EmpresaCuentaBancariaResource(
            $this->cuentas->actualizar($empresa, $cuenta, $request->validated(), $request->user('api')),
        );
    }

    public function actualizarEstado(Request $request, Empresa $empresa, EmpresaCuentaBancaria $cuenta): EmpresaCuentaBancariaResource
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        return new EmpresaCuentaBancariaResource(
            $this->cuentas->actualizarEstado($empresa, $cuenta, $datos['activo'], $request->user('api')),
        );
    }
}
