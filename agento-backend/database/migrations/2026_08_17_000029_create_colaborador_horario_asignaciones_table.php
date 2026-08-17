<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_horario_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('horario_id')->constrained('horarios')->restrictOnDelete();
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->timestamps();

            $table->index(
                ['empresa_id', 'colaborador_id', 'vigencia_desde'],
                'colaborador_horario_asignaciones_vigencia_idx',
            );
        });

        $ahora = now();
        DB::table('colaboradores')
            ->orderBy('id')
            ->chunkById(500, function ($colaboradores) use ($ahora) {
                DB::table('colaborador_horario_asignaciones')->insert(
                    $colaboradores->map(fn ($colaborador) => [
                        'empresa_id' => $colaborador->empresa_id,
                        'colaborador_id' => $colaborador->id,
                        'horario_id' => $colaborador->horario_id,
                        'vigencia_desde' => $colaborador->fecha_ingreso,
                        'vigencia_hasta' => null,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_horario_asignaciones');
    }
};
