<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo normalizado de "por qué un colaborador no trabajó un día" —
 * hoy AsistenciaPermiso.tipo es un enum de aplicación cerrado
 * (Rule::in en StoreAsistenciaPermisoRequest), sin ningún lugar donde
 * asociarle el código de suspensión que exige la estructura E15/.snl de
 * PLAME (Tabla 21 de SUNAT).
 *
 * NO se inventa ningún código SUNAT — codigo_sunat_suspension queda NULL
 * ("pendiente de catálogo SUNAT") hasta que se cargue la Tabla 21 real.
 *
 * remunerada es NULLABLE a propósito: para tipos donde SIEMPRE es igual
 * (vacaciones = siempre remunerada, falta_injustificada = nunca) se fija
 * acá; para tipos donde varía caso a caso (permiso médico/personal, ya
 * decidido por AsistenciaPermiso.con_goce en cada solicitud puntual) se
 * deja NULL — "depende de la instancia", no un valor fijo del catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_ausencia', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->boolean('afecta_asistencia')->default(true);
            $table->boolean('remunerada')->nullable();
            $table->string('codigo_sunat_suspension', 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Se inserta acá (no solo en un Seeder) para que un simple
        // `php artisan migrate` sea suficiente y no dependa de que alguien
        // recuerde correr los seeders en un entorno ya existente —
        // mismo motivo por el que TipoAusenciaSeeder usa updateOrCreate:
        // ambos caminos deben poder correr sin duplicar filas.
        $ahora = now();
        DB::table('tipos_ausencia')->insert([
            ['codigo' => 'vacaciones', 'nombre' => 'Vacaciones', 'afecta_asistencia' => true, 'remunerada' => true, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo' => 'medico', 'nombre' => 'Descanso médico / permiso médico', 'afecta_asistencia' => true, 'remunerada' => null, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo' => 'personal', 'nombre' => 'Permiso personal', 'afecta_asistencia' => true, 'remunerada' => null, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo' => 'capacitacion', 'nombre' => 'Capacitación', 'afecta_asistencia' => true, 'remunerada' => null, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo' => 'comision_servicio', 'nombre' => 'Comisión de servicio', 'afecta_asistencia' => true, 'remunerada' => null, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo' => 'otro', 'nombre' => 'Otro', 'afecta_asistencia' => true, 'remunerada' => null, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            // No corresponde a un AsistenciaPermiso — es la ausencia de uno
            // (estado=falta sin permiso aprobado que la cubra), ver
            // AsistenciaOperacionService::diasNoLaboradosPorTipo().
            ['codigo' => 'falta_injustificada', 'nombre' => 'Falta injustificada', 'afecta_asistencia' => true, 'remunerada' => false, 'codigo_sunat_suspension' => null, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_ausencia');
    }
};
