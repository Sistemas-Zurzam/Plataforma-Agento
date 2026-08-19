<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tramos progresivos de renta (5ta/4ta), versionados por vigencia_desde
     * — igual patrón "solo insertar, nunca actualizar" que
     * parametro_laboral_valores/comisiones_afp. Un cambio de tramos se
     * modela insertando el set completo nuevo con una vigencia_desde más
     * reciente; el motor toma el set completo cuya vigencia_desde sea la
     * más reciente <= fecha de corte.
     */
    public function up(): void
    {
        Schema::create('tramos_renta', function (Blueprint $table) {
            $table->id();
            $table->string('categoria'); // quinta | cuarta
            $table->unsignedTinyInteger('orden');
            $table->decimal('limite_inferior_uit', 8, 2);
            $table->decimal('limite_superior_uit', 8, 2)->nullable();
            $table->decimal('tasa_porcentaje', 5, 2);
            $table->date('vigencia_desde');
            $table->timestamps();

            $table->index(['categoria', 'vigencia_desde', 'orden'], 'tramos_renta_indice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramos_renta');
    }
};
