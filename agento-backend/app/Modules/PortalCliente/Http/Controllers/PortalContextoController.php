<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Role;
use App\Modules\PortalCliente\Application\AlcancePortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalContextoController extends Controller
{
    /**
     * Resuelve todo desde el usuario autenticado y su empresa activa
     * (users.empresa_id, vía User::currentRole()) — nunca desde un
     * empresa_id enviado por query, body o parámetros de ruta.
     *
     * "capacidades" solo marca como disponibles las acciones con permiso Y
     * con integración real; vacaciones e informes por correo se informan
     * como no disponibles por dependencia pendiente.
     */
    public function contexto(Request $request): JsonResponse
    {
        $alcance = AlcancePortal::desdeRequest($request);
        $usuario = $alcance->usuario;
        $empresa = $alcance->empresa;
        $rol = $usuario->currentRole();

        $permisosPortal = $rol
            ? $rol->permissions->pluck('clave')
                ->filter(fn (string $clave) => str_starts_with($clave, 'portal.'))
                ->values()
            : collect();
        // Cada sección exige el permiso Y el servicio contratado por la
        // empresa (planilla o reclutamiento); las rutas lo vuelven a validar.
        $servicios = $empresa->servicios();
        $planilla = fn (bool $permitido): bool => $permitido && $servicios['planilla'];
        $reclutamiento = fn (bool $permitido): bool => $permitido && $servicios['reclutamiento'];
        $verPersonal = $alcance->puede('seleccion.solicitudes.crear') || $alcance->puede('seleccion.seguimiento')
            || $alcance->puede('portal.personal.ver');

        return response()->json([
            'data' => [
                'empresa' => [
                    'id' => $empresa->id,
                    'razon_social' => $empresa->razon_social,
                    'nombre_comercial' => $empresa->nombre_comercial,
                ],
                'rol_actual' => $rol?->clave,
                'es_portal_cliente' => $rol?->clave === Role::CLIENTE_EMPRESA,
                'permisos' => $permisosPortal,
                'servicios' => $servicios,
                'alcance' => $alcance->empresaCompleta ? 'empresa' : 'areas',
                'areas' => $alcance->areasAutorizadas(),
                'capacidades' => [
                    'inicio' => $alcance->puede('portal.acceder'),
                    'equipo' => $planilla($alcance->puede('portal.asistencia.ver')),
                    'horas_extra_ver' => $planilla($alcance->puede('portal.horas_extra.ver')),
                    'horas_extra_aprobar' => $planilla($alcance->puede('portal.horas_extra.aprobar')),
                    'permisos_ver' => $planilla($alcance->puede('portal.permisos.ver')),
                    'permisos_solicitar' => $planilla($alcance->puede('portal.permisos.solicitar')),
                    'planificacion' => $planilla($alcance->puede('portal.planificacion.gestionar')),
                    'bonos' => $planilla($alcance->puede('portal.bonos.proponer')),
                    'ceses' => $planilla($alcance->puede('portal.ceses.solicitar')),
                    'personal' => $reclutamiento($verPersonal),
                    'personal_crear' => $reclutamiento($alcance->puede('seleccion.solicitudes.crear')),
                    'vacaciones' => false,
                    'informes_correo' => false,
                ],
                'dependencias_pendientes' => [
                    'vacaciones' => 'Agento aún no tiene un módulo de vacaciones con saldos y validaciones propias.',
                    'informes_correo' => 'Agento aún no tiene infraestructura de reportes programados por correo.',
                ],
            ],
        ]);
    }
}
