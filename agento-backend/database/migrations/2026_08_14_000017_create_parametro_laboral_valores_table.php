<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parametro_laboral_valores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('definicion_id')->constrained('parametro_laboral_definiciones')->cascadeOnDelete();
            $table->string('regimen_laboral');
            $table->date('vigencia_desde');
            $table->decimal('valor', 12, 2);
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'definicion_id', 'regimen_laboral', 'vigencia_desde'],
                'parametro_laboral_valores_unico',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametro_laboral_valores');
    }
};
