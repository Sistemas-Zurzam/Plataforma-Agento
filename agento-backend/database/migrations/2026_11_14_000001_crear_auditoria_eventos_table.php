<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría (append-only). Cada fila es un hecho histórico:
 * por eso conserva una copia del nombre/rol del usuario al momento del
 * evento (el usuario puede renombrarse o eliminarse después).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 150)->nullable();
            $table->string('usuario_rol', 60)->nullable();
            // request = operación de la API | ui = click/navegación | auth = login/logout
            $table->string('tipo', 20);
            $table->string('accion', 190);
            $table->string('modulo', 60)->nullable();
            $table->string('metodo', 10)->nullable();
            $table->string('ruta', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('recurso_tipo', 100)->nullable();
            $table->string('recurso_id', 60)->nullable();
            $table->json('payload')->nullable();
            $table->json('cambios')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'created_at']);
            $table->index(['empresa_id', 'user_id', 'created_at']);
            $table->index(['empresa_id', 'tipo', 'created_at']);
            $table->index(['empresa_id', 'modulo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_eventos');
    }
};
