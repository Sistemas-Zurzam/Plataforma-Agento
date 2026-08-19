<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un locador (Recibos por Honorarios) puede presentar una constancia de
     * suspensión de retenciones de renta de 4ta — si la tiene, el motor de
     * honorarios nunca debe retener, sin importar el monto del recibo.
     * Aditivo, solo relevante para colaboradores con tipo_contrato =
     * locacion_servicios; el resto simplemente ignora este campo.
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('tiene_suspension_renta_4ta')->default(false)->after('tiene_hijos_asignacion_familiar');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('tiene_suspension_renta_4ta');
        });
    }
};
