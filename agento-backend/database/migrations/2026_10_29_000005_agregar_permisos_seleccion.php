<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos de Selección y Reclutamiento sobre el RBAC existente. Asignación
 * inicial (solo agrega, nunca quita permisos existentes):
 * - administrador: todos (igual que PermissionSeeder).
 * - talento_cultura (RYS): todos los de selección.
 * - gerencia / jefe_area (GER): crear solicitudes y seguimiento de sus áreas.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const PERMISOS = [
        'seleccion.solicitudes.crear' => 'Crear solicitudes de personal',
        'seleccion.solicitudes.decidir' => 'Aceptar, devolver o rechazar solicitudes de personal',
        'seleccion.vacantes.gestionar' => 'Gestionar procesos, plazas y publicaciones',
        'seleccion.candidatos.gestionar' => 'Gestionar candidatos y postulaciones',
        'seleccion.evaluaciones.gestionar' => 'Gestionar pruebas y entrevistas',
        'seleccion.contratar' => 'Contratar candidatos seleccionados',
        'seleccion.seguimiento' => 'Ver seguimiento y dashboard de selección',
    ];

    private const ASIGNACIONES = [
        'administrador' => null,
        'talento_cultura' => null,
        'gerencia' => ['seleccion.solicitudes.crear', 'seleccion.seguimiento'],
        'jefe_area' => ['seleccion.solicitudes.crear', 'seleccion.seguimiento'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $ahora = now();

            foreach (self::PERMISOS as $clave => $nombre) {
                DB::table('permissions')->updateOrInsert(
                    ['clave' => $clave],
                    ['nombre' => $nombre, 'grupo' => 'Selección y Reclutamiento', 'created_at' => $ahora, 'updated_at' => $ahora],
                );
            }

            $permisoIds = DB::table('permissions')->whereIn('clave', array_keys(self::PERMISOS))->pluck('id', 'clave');

            foreach (self::ASIGNACIONES as $rolClave => $claves) {
                $rolId = DB::table('roles')->where('clave', $rolClave)->value('id');
                if (! $rolId) {
                    continue;
                }

                $ids = $claves === null ? $permisoIds->values() : $permisoIds->only($claves)->values();
                DB::table('role_permission')->insertOrIgnore($ids->map(fn (int $permisoId): array => [
                    'role_id' => $rolId,
                    'permission_id' => $permisoId,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ])->all());
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permisoIds = DB::table('permissions')->whereIn('clave', array_keys(self::PERMISOS))->pluck('id');
            DB::table('role_permission')->whereIn('permission_id', $permisoIds)->delete();
            DB::table('permissions')->whereIn('id', $permisoIds)->delete();
        });
    }
};
