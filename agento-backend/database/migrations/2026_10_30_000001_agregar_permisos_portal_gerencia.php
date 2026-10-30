<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Portal de Empresa para gerencia (GER). Claves NUEVAS a propósito: el rol
 * cliente_empresa ya tiene portal.horas_extra.resolver y
 * portal.bonificaciones.gestionar (sin uso hasta hoy); reutilizarlas
 * habilitaría escritura a clientes externos, fuera del alcance de esta
 * entrega.
 *
 * portal.alcance_empresa conserva el comportamiento previo del Portal
 * Cliente (toda la empresa activa). Sin ese permiso, el alcance es
 * únicamente el de las áreas autorizadas del usuario.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const PERMISOS = [
        'portal.alcance_empresa' => 'Ver toda la empresa activa en el Portal (sin restricción de áreas)',
        'portal.horas_extra.aprobar' => 'Aprobar o rechazar horas extra de su equipo (aprobación de gerencia)',
        'portal.permisos.solicitar' => 'Solicitar permisos laborales para su equipo',
        'portal.planificacion.gestionar' => 'Planificar turnos rotativos de su equipo',
        'portal.bonos.proponer' => 'Proponer bonos y comisiones para su equipo',
        'portal.ceses.solicitar' => 'Solicitar ceses de colaboradores de su equipo',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ASIGNACIONES = [
        'gerencia' => [
            'portal.acceder', 'portal.asistencia.ver', 'portal.horas_extra.ver', 'portal.permisos.ver',
            'portal.horas_extra.aprobar', 'portal.permisos.solicitar', 'portal.planificacion.gestionar',
            'portal.bonos.proponer', 'portal.ceses.solicitar',
        ],
        'cliente_empresa' => ['portal.alcance_empresa'],
        'administrador' => [
            'portal.alcance_empresa', 'portal.horas_extra.aprobar', 'portal.permisos.solicitar',
            'portal.planificacion.gestionar', 'portal.bonos.proponer', 'portal.ceses.solicitar',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $ahora = now();
            foreach (self::PERMISOS as $clave => $nombre) {
                DB::table('permissions')->updateOrInsert(
                    ['clave' => $clave],
                    ['nombre' => $nombre, 'grupo' => 'Portal de Empresa', 'created_at' => $ahora, 'updated_at' => $ahora],
                );
            }

            foreach (self::ASIGNACIONES as $rolClave => $claves) {
                $rolId = DB::table('roles')->where('clave', $rolClave)->value('id');
                if (! $rolId) {
                    continue;
                }
                $filas = DB::table('permissions')->whereIn('clave', $claves)->pluck('id')
                    ->map(fn (int $permisoId): array => [
                        'role_id' => $rolId, 'permission_id' => $permisoId,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ])->all();
                DB::table('role_permission')->insertOrIgnore($filas);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permisoIds = DB::table('permissions')->whereIn('clave', array_keys(self::PERMISOS))->pluck('id');
            DB::table('role_permission')->whereIn('permission_id', $permisoIds)->delete();
            DB::table('permissions')->whereIn('id', $permisoIds)->delete();

            // Las asignaciones de permisos de lectura existentes a gerencia
            // se retiran para volver al estado previo.
            $gerenciaId = DB::table('roles')->where('clave', 'gerencia')->value('id');
            if ($gerenciaId) {
                $lectura = DB::table('permissions')
                    ->whereIn('clave', ['portal.acceder', 'portal.asistencia.ver', 'portal.horas_extra.ver', 'portal.permisos.ver'])
                    ->pluck('id');
                DB::table('role_permission')->where('role_id', $gerenciaId)->whereIn('permission_id', $lectura)->delete();
            }
        });
    }
};
