<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Punto único para consultar incidencias de asistencia PENDIENTES que deben
 * bloquear una acción de Nóminas (aprobar boleta, cerrar ciclo) — evita que
 * BoletaService y CicloRemunerativoService repitan el mismo query y, sobre
 * todo, el mismo bypass del scope de empresa (ver comentario del método).
 */
class IncidenciasPendientesNominaService
{
    /**
     * Nunca se apoya en el scope automático de empresa de
     * AsistenciaIncidencia (empresa "activa" del usuario autenticado): un
     * administrador puede aprobar/cerrar sobre OTRA empresa que administra
     * sin haber cambiado su empresa activa — por eso se filtra
     * explícitamente por $empresa->id en vez de confiar en el scope.
     *
     * @param  iterable<int>  $colaboradorIds
     */
    public function query(Empresa $empresa, iterable $colaboradorIds, string $fechaInicio, string $fechaFin): Builder
    {
        return AsistenciaIncidencia::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
            ->whereIn('colaborador_id', $colaboradorIds)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE);
    }
}
