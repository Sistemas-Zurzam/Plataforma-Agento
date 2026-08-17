<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_identidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('person_id', 100);
            $table->timestamps();
            $table->unique(['empresa_id', 'person_id']);
            $table->unique(['empresa_id', 'colaborador_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('asistencia_identidades'); }
};
