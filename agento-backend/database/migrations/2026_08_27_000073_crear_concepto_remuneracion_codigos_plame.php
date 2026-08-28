<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de codigo_plame por concepto — mismo patrón append-only que
     * comisiones_afp/colaborador_remuneraciones (nunca se sobrescribe una
     * fila, cada cambio real inserta una nueva). La columna viva
     * conceptos_remuneracion.codigo_plame sigue siendo la que lee
     * BoletaService al calcular (igual que antes, snapshot "al momento del
     * cálculo") — esta tabla es el registro de auditoría paralelo que
     * permite ver cuándo cambió y por qué, sin perder trazabilidad.
     *
     * Nombre de tabla acortado a "concepto_codigos_plame" (en vez de
     * "concepto_remuneracion_codigos_plame"): con el nombre largo, MySQL
     * rechaza el nombre autogenerado de la foreign key por superar 64
     * caracteres (identifier name too long).
     */
    public function up(): void
    {
        Schema::create('concepto_codigos_plame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concepto_remuneracion_id')->constrained('conceptos_remuneracion')->cascadeOnDelete();
            $table->string('codigo_plame', 4)->nullable();
            $table->string('descripcion_sunat')->nullable();
            $table->date('vigencia_desde');
            $table->foreignId('actualizado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['concepto_remuneracion_id', 'vigencia_desde'], 'concepto_codigos_plame_indice');
        });

        // Backfill: no existía historial antes — se registra el estado
        // actual como primera fila, mismo criterio que
        // colaborador_condiciones_laborales.
        DB::statement(<<<'SQL'
            INSERT INTO concepto_codigos_plame
                (concepto_remuneracion_id, codigo_plame, vigencia_desde, created_at, updated_at)
            SELECT id, codigo_plame, CURDATE(), NOW(), NOW()
            FROM conceptos_remuneracion
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('concepto_codigos_plame');
    }
};
