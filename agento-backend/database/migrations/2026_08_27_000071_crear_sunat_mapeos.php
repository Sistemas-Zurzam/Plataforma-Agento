<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equivalencia genérica "valor interno fijo de Agento" → "código oficial
     * SUNAT", para los catálogos que son enumeraciones de código (Rule::in)
     * y no tienen tabla propia — a diferencia de tipos_ausencia o
     * conceptos_remuneracion, que sí tienen su propio catálogo y por eso NO
     * se duplican acá (ver migraciones de codigo_sunat_suspension y
     * concepto_codigos_plame).
     *
     * NUNCA se reemplaza el valor interno de Agento por el código SUNAT en
     * ningún lugar del sistema — esta tabla es solo la capa de equivalencia
     * que leerá el futuro exportador PLAME.
     */
    public function up(): void
    {
        Schema::create('sunat_mapeos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->string('clave_interna');
            $table->string('codigo_sunat')->nullable();
            $table->string('descripcion_sunat')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('actualizado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo', 'clave_interna']);
        });

        // Valores reales usados hoy por el sistema (Rule::in en
        // StoreColaboradorRequest / Colaborador::REGIMENES_LABORALES) — sin
        // código SUNAT todavía, nunca inventado.
        $filas = [
            ['tipo' => 'tipo_documento', 'clave_interna' => 'dni'],
            ['tipo' => 'tipo_documento', 'clave_interna' => 'ce'],
            ['tipo' => 'tipo_documento', 'clave_interna' => 'pasaporte'],

            ['tipo' => 'tipo_trabajador', 'clave_interna' => 'trabajador'],
            ['tipo' => 'tipo_trabajador', 'clave_interna' => 'practicante'],
            ['tipo' => 'tipo_trabajador', 'clave_interna' => 'locador'],

            ['tipo' => 'regimen_laboral', 'clave_interna' => 'General'],
            ['tipo' => 'regimen_laboral', 'clave_interna' => 'Micro Empresa'],
            ['tipo' => 'regimen_laboral', 'clave_interna' => 'Pequeña Empresa'],
            ['tipo' => 'regimen_laboral', 'clave_interna' => 'Locacion de Servicios'],

            // Único tipo de comprobante que Agento emite hoy para locadores
            // (ver BoletaComprobanteRh) — Tabla 23 de SUNAT no está en el
            // Anexo 3 disponible, así que no se listan más opciones que las
            // que el sistema realmente puede producir.
            ['tipo' => 'tipo_comprobante_rh', 'clave_interna' => 'recibo_honorarios'],
        ];

        $ahora = now();
        foreach ($filas as &$fila) {
            $fila['created_at'] = $ahora;
            $fila['updated_at'] = $ahora;
        }
        unset($fila);

        DB::table('sunat_mapeos')->insert($filas);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sunat_mapeos');
    }
};
