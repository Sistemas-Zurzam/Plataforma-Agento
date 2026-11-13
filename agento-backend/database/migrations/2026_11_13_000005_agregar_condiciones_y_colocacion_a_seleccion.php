<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Condiciones comerciales del servicio de reclutamiento por solicitud
 *   (las registra Agento; no se muestran en el Portal de Empresa).
 * - Datos de la cobertura de cada plaza: fecha de ingreso y salario acordado.
 *   Para empresas sin servicio de planilla la contratación es una
 *   "colocación": se cubre la plaza sin crear colaborador en Personas.
 * - Permiso portal.personal.ver: seguimiento de solo lectura de las
 *   solicitudes de personal para el usuario cliente de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seleccion_solicitudes', function (Blueprint $table) {
            $table->string('cobro_modalidad', 25)->nullable()->after('urgente');
            $table->decimal('cobro_monto', 12, 2)->nullable()->after('cobro_modalidad');
            $table->string('cobro_moneda', 3)->nullable()->after('cobro_monto');
            $table->unsignedSmallInteger('garantia_dias')->nullable()->after('cobro_moneda');
            $table->text('condiciones_notas')->nullable()->after('garantia_dias');
        });

        Schema::table('seleccion_plazas', function (Blueprint $table) {
            $table->date('fecha_ingreso')->nullable()->after('colaborador_id');
            $table->decimal('salario_acordado', 12, 2)->nullable()->after('fecha_ingreso');
        });

        DB::transaction(function (): void {
            $ahora = now();
            DB::table('permissions')->updateOrInsert(
                ['clave' => 'portal.personal.ver'],
                ['nombre' => 'Ver el avance de las solicitudes de personal en el portal', 'grupo' => 'Portal Cliente', 'created_at' => $ahora, 'updated_at' => $ahora],
            );
            $permisoId = DB::table('permissions')->where('clave', 'portal.personal.ver')->value('id');

            foreach (['cliente_empresa', 'administrador'] as $clave) {
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
            $permisoId = DB::table('permissions')->where('clave', 'portal.personal.ver')->value('id');
            if ($permisoId) {
                DB::table('role_permission')->where('permission_id', $permisoId)->delete();
                DB::table('permissions')->where('id', $permisoId)->delete();
            }
        });

        Schema::table('seleccion_plazas', function (Blueprint $table) {
            $table->dropColumn(['fecha_ingreso', 'salario_acordado']);
        });

        Schema::table('seleccion_solicitudes', function (Blueprint $table) {
            $table->dropColumn(['cobro_modalidad', 'cobro_monto', 'cobro_moneda', 'garantia_dias', 'condiciones_notas']);
        });
    }
};
