<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asistencia_solicitudes_area')) {
            Schema::create('asistencia_solicitudes_area', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->string('tipo', 40);
                $table->string('origen', 30);
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->text('motivo');
                $table->string('medio_recepcion', 30)->nullable();
                $table->string('estado', 30)->default('pendiente_responsable');
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['empresa_id', 'estado', 'fecha_inicio']);
            });
        }

        if (! Schema::hasTable('asistencia_solicitud_colaboradores')) {
            Schema::create('asistencia_solicitud_colaboradores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('solicitud_id')->constrained('asistencia_solicitudes_area')->cascadeOnDelete();
                $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
                $table->unique(['solicitud_id', 'colaborador_id'], 'asistencia_solicitud_colaborador_unique');
            });
        } elseif (! collect(Schema::getIndexes('asistencia_solicitud_colaboradores'))
            ->contains(fn (array $index) => $index['name'] === 'asistencia_solicitud_colaborador_unique')) {
            Schema::table('asistencia_solicitud_colaboradores', function (Blueprint $table) {
                $table->unique(['solicitud_id', 'colaborador_id'], 'asistencia_solicitud_colaborador_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_solicitud_colaboradores');
        Schema::dropIfExists('asistencia_solicitudes_area');
    }
};
