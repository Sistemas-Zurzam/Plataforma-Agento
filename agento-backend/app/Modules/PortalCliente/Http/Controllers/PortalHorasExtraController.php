<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Application\DecidirHoraExtraGerencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\PortalDecidirHoraExtraRequest;
use App\Modules\PortalCliente\Http\Resources\PortalHoraExtraResource;

class PortalHorasExtraController extends Controller
{
    public function __construct(private readonly DecidirHoraExtraGerencia $decidir) {}

    /**
     * El id de ruta se resuelve SIEMPRE dentro del alcance (empresa + áreas
     * + colaborador activo): una hora extra ajena responde 404.
     */
    public function decidir(PortalDecidirHoraExtraRequest $request, int $horaExtra): PortalHoraExtraResource
    {
        $alcance = AlcancePortal::desdeRequest($request);
        $registro = $alcance->restringir(AsistenciaHoraExtra::query(), 'asistencia_horas_extra')
            ->whereKey($horaExtra)->with('colaborador')->first();
        abort_unless($registro, 404, 'La hora extra no existe o no pertenece a tu equipo.');
        $alcance->asegurarNoEsPropio($registro->colaborador, 'aprobar horas extra');

        $datos = $request->validated();

        return new PortalHoraExtraResource($this->decidir->ejecutar(
            $registro->id, $alcance->empresa->id, $datos['accion'], $datos['minutos'] ?? null,
            $datos['motivo'], $datos['version'], $alcance->usuario,
        ));
    }
}
