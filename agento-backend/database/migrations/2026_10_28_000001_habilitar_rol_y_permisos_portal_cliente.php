<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const PERMISOS = [
        'portal.acceder' => 'Acceder al Portal Cliente',
        'portal.asistencia.ver' => 'Ver asistencia en el Portal Cliente',
        'portal.asistencia.editar' => 'Editar asistencia en el Portal Cliente',
        'portal.incidencias.resolver' => 'Resolver incidencias en el Portal Cliente',
        'portal.horas_extra.ver' => 'Ver horas extra en el Portal Cliente',
        'portal.horas_extra.resolver' => 'Aprobar o rechazar horas extra en el Portal Cliente',
        'portal.permisos.ver' => 'Ver permisos laborales en el Portal Cliente',
        'portal.remuneraciones.ver' => 'Ver remuneraciones en el Portal Cliente',
        'portal.bonificaciones.gestionar' => 'Gestionar bonificaciones en el Portal Cliente',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $ahora = now();
            $rolCliente = DB::table('roles')->where('clave', 'cliente_empresa')->first();

            // En producción se creó "Portal" desde la UI antes de que existiera
            // esta migración. Reutilizarlo conserva los usuarios ya vinculados.
            if (! $rolCliente) {
                $rolPortalManual = DB::table('roles')
                    ->where('clave', 'portal')
                    ->whereRaw('LOWER(nombre) = ?', ['portal'])
                    ->first();

                if ($rolPortalManual) {
                    DB::table('roles')->where('id', $rolPortalManual->id)->update([
                        'clave' => 'cliente_empresa',
                        'nombre' => 'Cliente de Empresa',
                        'updated_at' => $ahora,
                    ]);
                    $rolClienteId = $rolPortalManual->id;
                } else {
                    $rolClienteId = DB::table('roles')->insertGetId([
                        'clave' => 'cliente_empresa',
                        'nombre' => 'Cliente de Empresa',
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }
            } else {
                $rolClienteId = $rolCliente->id;
                DB::table('roles')->where('id', $rolClienteId)->update([
                    'nombre' => 'Cliente de Empresa',
                    'updated_at' => $ahora,
                ]);
            }

            foreach (self::PERMISOS as $clave => $nombre) {
                DB::table('permissions')->updateOrInsert(
                    ['clave' => $clave],
                    [
                        'nombre' => $nombre,
                        'grupo' => 'Portal Cliente',
                        'updated_at' => $ahora,
                        'created_at' => $ahora,
                    ],
                );
            }

            $permisoIds = DB::table('permissions')
                ->whereIn('clave', array_keys(self::PERMISOS))
                ->pluck('id');

            // Este rol nunca debe heredar permisos del panel administrativo.
            DB::table('role_permission')->where('role_id', $rolClienteId)->delete();

            $asignacionesCliente = $permisoIds->map(fn (int $permisoId): array => [
                'role_id' => $rolClienteId,
                'permission_id' => $permisoId,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();
            DB::table('role_permission')->insertOrIgnore($asignacionesCliente);

            $administradorId = DB::table('roles')->where('clave', 'administrador')->value('id');
            if ($administradorId) {
                $asignacionesAdministrador = $permisoIds->map(fn (int $permisoId): array => [
                    'role_id' => $administradorId,
                    'permission_id' => $permisoId,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ])->all();
                DB::table('role_permission')->insertOrIgnore($asignacionesAdministrador);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permisoIds = DB::table('permissions')
                ->whereIn('clave', array_keys(self::PERMISOS))
                ->pluck('id');

            DB::table('role_permission')->whereIn('permission_id', $permisoIds)->delete();
            DB::table('permissions')->whereIn('id', $permisoIds)->delete();

            $rolClienteId = DB::table('roles')->where('clave', 'cliente_empresa')->value('id');
            if ($rolClienteId && ! DB::table('empresa_user')->where('role_id', $rolClienteId)->exists()) {
                DB::table('roles')->where('id', $rolClienteId)->delete();
            }
        });
    }
};
