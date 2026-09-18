<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credencial segura para marcación de asistencia por carnet (código de
 * barras). El valor impreso en el carnet NUNCA se guarda acá — solo su hash
 * (`token_hash`), igual que una contraseña: el backend recibe el código
 * leído, lo hashea y busca por ese hash.
 *
 * "Solo una credencial activa por colaborador+tipo" se garantiza a nivel de
 * BASE DE DATOS con `colaborador_id_si_activa`: columna GENERADA (virtual)
 * que vale `colaborador_id` cuando `estado='activa'` y NULL en cualquier
 * otro caso, con un unique sobre ella. MySQL no compara NULLs entre sí en un
 * índice unique, así que puede haber cualquier cantidad de credenciales
 * revocadas (todas con NULL ahí), pero nunca dos con la MISMA
 * (empresa_id, tipo, colaborador_id) marcadas "activa" a la vez — ni
 * siquiera si dos requests concurrentes intentan generar la primera
 * credencial de un colaborador al mismo tiempo (el `lockForUpdate()` en
 * `CarnetCredentialService::generar()` no alcanza a proteger ESE caso
 * puntual, porque no hay ninguna fila previa que bloquear). Verificado con
 * dos procesos PHP reales concurrentes contra MySQL — ver reporte de la
 * ronda de endurecimiento. `tipo` deja espacio para futuras credenciales
 * sin crear otra tabla, sin implementar nada más que carnet_codigo_barras
 * hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_credenciales_acceso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('tipo', 50)->default('carnet_codigo_barras');
            $table->string('token_hash', 64)->unique();
            $table->string('estado', 20)->default('activa');
            $table->dateTime('generado_at');
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revocado_at')->nullable();
            $table->foreignId('revocado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_revocacion', 255)->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'colaborador_id', 'tipo', 'estado'], 'colaborador_credenciales_busqueda_index');
        });

        // Columna generada + unique condicional — ver comentario de arriba.
        // Statement crudo porque Blueprint::virtualAs() de Laravel no
        // soporta bien una expresión CASE con comparación de string en
        // todas las versiones; el SQL generado a mano es más predecible y
        // ya está verificado contra MySQL real.
        DB::statement(
            "ALTER TABLE colaborador_credenciales_acceso
                ADD COLUMN colaborador_id_si_activa BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN estado = 'activa' THEN colaborador_id ELSE NULL END) VIRTUAL"
        );
        Schema::table('colaborador_credenciales_acceso', function (Blueprint $table) {
            $table->unique(['empresa_id', 'tipo', 'colaborador_id_si_activa'], 'colaborador_credenciales_una_activa_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_credenciales_acceso');
    }
};
