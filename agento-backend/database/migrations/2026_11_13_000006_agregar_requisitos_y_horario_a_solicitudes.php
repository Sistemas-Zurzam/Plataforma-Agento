<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Requisitos y horario laboral del requerimiento, en el formato con el que
 *   las empresas cliente describen el puesto (descripción, funciones,
 *   requisitos, horario, remuneración).
 * - Permiso seleccion.solicitudes.todas_areas: gerencia solicita personal
 *   para cualquier área de su empresa, no solo para las que dirige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seleccion_solicitudes', function (Blueprint $table) {
            $table->text('requisitos')->nullable()->after('actividades');
            $table->string('horario', 500)->nullable()->after('requisitos');
        });

        DB::transaction(function (): void {
            $ahora = now();
            DB::table('permissions')->updateOrInsert(
                ['clave' => 'seleccion.solicitudes.todas_areas'],
                ['nombre' => 'Solicitar personal para cualquier área de la empresa', 'grupo' => 'Selección y Reclutamiento', 'created_at' => $ahora, 'updated_at' => $ahora],
            );
            $permisoId = DB::table('permissions')->where('clave', 'seleccion.solicitudes.todas_areas')->value('id');

            foreach (['gerencia', 'administrador'] as $clave) {
                $rolId = DB::table('roles')->where('clave', $clave)->value('id');
                if ($rolId) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $rolId, 'permission_id' => $permisoId, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permisoId = DB::table('permissions')->where('clave', 'seleccion.solicitudes.todas_areas')->value('id');
            if ($permisoId) {
                DB::table('role_permission')->where('permission_id', $permisoId)->delete();
                DB::table('permissions')->where('id', $permisoId)->delete();
            }
        });

        Schema::table('seleccion_solicitudes', function (Blueprint $table) {
            $table->dropColumn(['requisitos', 'horario']);
        });
    }
};
