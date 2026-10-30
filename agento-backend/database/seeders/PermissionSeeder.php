<?php

namespace Database\Seeders;

use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Catálogo fijo de permisos: cada uno corresponde a una acción real ya
     * implementada (ver el middleware EnsurePermission y las rutas en
     * routes/api.php). No es editable desde la UI — el admin solo asigna
     * estos permisos a roles, no crea permisos nuevos.
     */
    public function run(): void
    {
        $permisos = [
            ['clave' => 'empresas.crear', 'nombre' => 'Crear empresas', 'grupo' => 'Empresas'],
            ['clave' => 'empresas.editar', 'nombre' => 'Editar empresas', 'grupo' => 'Empresas'],
            ['clave' => 'sedes.crear', 'nombre' => 'Crear sedes', 'grupo' => 'Sedes'],
            ['clave' => 'sedes.editar', 'nombre' => 'Editar sedes', 'grupo' => 'Sedes'],
            ['clave' => 'areas.crear', 'nombre' => 'Crear áreas', 'grupo' => 'Áreas'],
            ['clave' => 'usuarios.ver', 'nombre' => 'Ver usuarios y roles', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.crear', 'nombre' => 'Crear usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.editar', 'nombre' => 'Editar usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.cambiar_rol', 'nombre' => 'Cambiar rol de usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.inactivar', 'nombre' => 'Activar o inactivar usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'usuarios.eliminar', 'nombre' => 'Eliminar usuarios', 'grupo' => 'Usuarios y Roles'],
            ['clave' => 'parametros_laborales.ver', 'nombre' => 'Ver parámetros laborales', 'grupo' => 'Parámetros Laborales'],
            ['clave' => 'parametros_laborales.editar', 'nombre' => 'Editar parámetros laborales', 'grupo' => 'Parámetros Laborales'],
            ['clave' => 'comisiones_afp.ver', 'nombre' => 'Ver comisiones AFP', 'grupo' => 'Comisiones AFP'],
            ['clave' => 'comisiones_afp.editar', 'nombre' => 'Editar comisiones AFP', 'grupo' => 'Comisiones AFP'],
            ['clave' => 'horarios.ver', 'nombre' => 'Ver horarios', 'grupo' => 'Horarios'],
            ['clave' => 'horarios.crear', 'nombre' => 'Crear horarios', 'grupo' => 'Horarios'],
            ['clave' => 'horarios.editar', 'nombre' => 'Editar horarios', 'grupo' => 'Horarios'],
            ['clave' => 'colaboradores.ver', 'nombre' => 'Ver colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'asistencia.ver', 'nombre' => 'Ver asistencia', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.procesar', 'nombre' => 'Procesar asistencia', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.importar', 'nombre' => 'Importar marcaciones', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.incidencias', 'nombre' => 'Resolver incidencias', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.permisos', 'nombre' => 'Gestionar permisos laborales', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.gestiones_area', 'nombre' => 'Gestionar solicitudes del área', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.horas_extra', 'nombre' => 'Aprobar horas extra', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.periodos', 'nombre' => 'Gestionar períodos de asistencia', 'grupo' => 'Asistencia'],
            ['clave' => 'asistencia.aprobar_rrhh', 'nombre' => 'Aprobación final de RR.HH.', 'grupo' => 'Asistencia'],
            ['clave' => 'control_acceso.marcar', 'nombre' => 'Marcar asistencia por carnet (Kiosco de Control de Acceso)', 'grupo' => 'Asistencia'],
            ['clave' => 'colaboradores.crear', 'nombre' => 'Crear colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'colaboradores.editar', 'nombre' => 'Editar colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'colaboradores.cesar', 'nombre' => 'Cesar colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'colaboradores.eliminar', 'nombre' => 'Eliminar colaboradores', 'grupo' => 'Colaboradores'],
            ['clave' => 'nominas.ver', 'nombre' => 'Ver ciclos y boletas de remuneraciones', 'grupo' => 'Remuneraciones'],
            ['clave' => 'nominas.gestionar_ciclos', 'nombre' => 'Crear y gestionar ciclos remunerativos', 'grupo' => 'Remuneraciones'],
            ['clave' => 'nominas.calcular', 'nombre' => 'Calcular/recalcular planilla', 'grupo' => 'Remuneraciones'],
            ['clave' => 'nominas.aprobar', 'nombre' => 'Aprobar boletas', 'grupo' => 'Remuneraciones'],
            ['clave' => 'nominas.cerrar_periodo', 'nombre' => 'Cerrar y reabrir períodos', 'grupo' => 'Remuneraciones'],
            ['clave' => 'nominas.pagar', 'nombre' => 'Marcar boletas como pagadas', 'grupo' => 'Remuneraciones'],
            // Más restrictivo que nominas.ver a propósito: descargar este
            // TXT puede terminar en movimiento real de dinero una vez
            // cargado al banco (ver migración agregar_permiso_telecredito_exportar).
            ['clave' => 'nominas.telecredito_exportar', 'nombre' => 'Exportar archivo Telecrédito BCP', 'grupo' => 'Remuneraciones'],

            // Portal Cliente — permisos propios del encargado de una empresa
            // cliente. Deliberadamente separados de asistencia.*/nominas.*
            // (que son administrativos): un rol cliente_empresa nunca debe
            // ganar acceso a rutas admin por compartir un permiso existente.
            ['clave' => 'portal.acceder', 'nombre' => 'Acceder al Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.asistencia.ver', 'nombre' => 'Ver asistencia en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.asistencia.editar', 'nombre' => 'Editar asistencia en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.incidencias.resolver', 'nombre' => 'Resolver incidencias en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.horas_extra.ver', 'nombre' => 'Ver horas extra en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.horas_extra.resolver', 'nombre' => 'Aprobar o rechazar horas extra en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.permisos.ver', 'nombre' => 'Ver permisos laborales en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.remuneraciones.ver', 'nombre' => 'Ver remuneraciones en el Portal Cliente', 'grupo' => 'Portal Cliente'],
            ['clave' => 'portal.bonificaciones.gestionar', 'nombre' => 'Gestionar bonificaciones en el Portal Cliente', 'grupo' => 'Portal Cliente'],

            // Portal de Empresa (GER) — ver migración agregar_permisos_portal_gerencia.
            ['clave' => 'portal.alcance_empresa', 'nombre' => 'Ver toda la empresa activa en el Portal (sin restricción de áreas)', 'grupo' => 'Portal de Empresa'],
            ['clave' => 'portal.horas_extra.aprobar', 'nombre' => 'Aprobar o rechazar horas extra de su equipo (aprobación de gerencia)', 'grupo' => 'Portal de Empresa'],
            ['clave' => 'portal.permisos.solicitar', 'nombre' => 'Solicitar permisos laborales para su equipo', 'grupo' => 'Portal de Empresa'],
            ['clave' => 'portal.planificacion.gestionar', 'nombre' => 'Planificar turnos rotativos de su equipo', 'grupo' => 'Portal de Empresa'],
            ['clave' => 'portal.bonos.proponer', 'nombre' => 'Proponer bonos y comisiones para su equipo', 'grupo' => 'Portal de Empresa'],
            ['clave' => 'portal.ceses.solicitar', 'nombre' => 'Solicitar ceses de colaboradores de su equipo', 'grupo' => 'Portal de Empresa'],

            // Selección y Reclutamiento — ver migración agregar_permisos_seleccion.
            ['clave' => 'seleccion.solicitudes.crear', 'nombre' => 'Crear solicitudes de personal', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.solicitudes.decidir', 'nombre' => 'Aceptar, devolver o rechazar solicitudes de personal', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.vacantes.gestionar', 'nombre' => 'Gestionar procesos, plazas y publicaciones', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.candidatos.gestionar', 'nombre' => 'Gestionar candidatos y postulaciones', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.evaluaciones.gestionar', 'nombre' => 'Gestionar pruebas y entrevistas', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.contratar', 'nombre' => 'Contratar candidatos seleccionados', 'grupo' => 'Selección y Reclutamiento'],
            ['clave' => 'seleccion.seguimiento', 'nombre' => 'Ver seguimiento y dashboard de selección', 'grupo' => 'Selección y Reclutamiento'],
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['clave' => $permiso['clave']], $permiso);
        }

        // El rol administrador siempre tiene acceso total. EnsurePermission
        // ya lo deja pasar sin mirar la matriz, pero sincronizamos igual
        // para que la UI de Permisos lo muestre consistentemente tildado.
        $administrador = Role::administrador();
        $administrador->permissions()->sync(Permission::pluck('id'));

        // cliente_empresa recibe únicamente sus permisos portal.* — nunca
        // nominas.*/asistencia.* ni ningún permiso administrativo. Si el rol
        // aún no existe (ej. PermissionSeeder ejecutado solo, sin
        // RoleSeeder antes), se omite sin romper el seeding.
        // Selección: RYS (talento_cultura) opera todo el módulo; GER
        // (gerencia/jefe_area) solo crea solicitudes y hace seguimiento de sus áreas.
        $asignacionesSeleccion = [
            'talento_cultura' => Permission::where('clave', 'like', 'seleccion.%')->pluck('id'),
            // GER además opera el Portal de Empresa, siempre limitado a sus áreas.
            'gerencia' => Permission::whereIn('clave', [
                'seleccion.solicitudes.crear', 'seleccion.seguimiento',
                'portal.acceder', 'portal.asistencia.ver', 'portal.horas_extra.ver', 'portal.permisos.ver',
                'portal.horas_extra.aprobar', 'portal.permisos.solicitar', 'portal.planificacion.gestionar',
                'portal.bonos.proponer', 'portal.ceses.solicitar',
            ])->pluck('id'),
            'jefe_area' => Permission::whereIn('clave', ['seleccion.solicitudes.crear', 'seleccion.seguimiento'])->pluck('id'),
        ];
        foreach ($asignacionesSeleccion as $clave => $permisoIds) {
            Role::where('clave', $clave)->first()?->permissions()->syncWithoutDetaching($permisoIds);
        }

        $clienteEmpresa = Role::where('clave', Role::CLIENTE_EMPRESA)->first();
        if ($clienteEmpresa) {
            // Lista explícita: los permisos de escritura del Portal de
            // Empresa (GER) nunca se heredan al cliente externo.
            $clienteEmpresa->permissions()->sync(
                Permission::whereIn('clave', [
                    'portal.acceder', 'portal.asistencia.ver', 'portal.asistencia.editar', 'portal.incidencias.resolver',
                    'portal.horas_extra.ver', 'portal.horas_extra.resolver', 'portal.permisos.ver',
                    'portal.remuneraciones.ver', 'portal.bonificaciones.gestionar', 'portal.alcance_empresa',
                ])->pluck('id'),
            );
        }
    }
}
