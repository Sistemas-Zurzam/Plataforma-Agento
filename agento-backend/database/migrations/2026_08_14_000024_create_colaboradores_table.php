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
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->foreignId('horario_id')->constrained()->restrictOnDelete();
            $table->string('legajo');

            $table->string('nombres');
            $table->string('apellidos');
            $table->string('tipo_documento');
            $table->string('numero_documento');
            $table->date('fecha_nacimiento')->nullable();
            $table->string('pais_residencia')->nullable();
            $table->string('ciudad_residencia')->nullable();
            $table->string('distrito_residencia')->nullable();
            $table->string('direccion')->nullable();
            $table->string('email')->nullable();
            $table->string('celular_empleado');
            $table->string('celular_referencia');

            $table->string('cargo');
            $table->string('tipo_contrato');
            $table->string('regimen_laboral')->nullable();
            $table->string('tipo_trabajador');
            $table->boolean('contabilizar_tardanzas')->default(true);
            $table->boolean('contabilizar_horas_extra')->default(true);
            $table->date('fecha_ingreso');
            $table->date('fecha_fin_contrato')->nullable();

            $table->string('cts_cuenta')->nullable();
            $table->string('sistema_previsional')->nullable();
            $table->string('banco')->nullable();
            $table->string('numero_cuenta')->nullable();
            $table->string('tipo_cuenta')->nullable();
            $table->string('moneda_cuenta')->nullable();
            $table->string('cci')->nullable();

            $table->string('modalidad_trabajo');
            $table->unsignedSmallInteger('tolerancia_particular_minutos')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'legajo']);
            $table->unique(['empresa_id', 'tipo_documento', 'numero_documento']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
