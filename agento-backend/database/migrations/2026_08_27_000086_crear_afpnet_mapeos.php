<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalencia "valor interno de Agento" → "código oficial AFPnet" —
 * tabla COMPLETAMENTE SEPARADA de sunat_mapeos (PLAME). AFPnet usa su
 * propio catálogo de códigos, distinto del de SUNAT (ej. tipo de documento
 * DNI es "01" en SUNAT Tabla 3 pero "0" en AFPnet); nunca se cruzan.
 *
 * Códigos cargados según la Guía rápida oficial AFPnet para Empleadores
 * (actualización 20.08.26) y el macro de carga masiva entregado por
 * negocio — solo los valores que Agento realmente usa hoy:
 *  - tipo_documento: dni/ce/pasaporte (no "ruc": en Agento el RUC solo lo
 *    usan locadores, que están fuera de la población AFPnet).
 *  - afp: las 4 AFP vigentes que administra Agento (prima/profuturo/
 *    integra/habitat). AFP históricas (Horizonte, Unión Vida) NO se
 *    cargan — Agento no las usa ni tiene períodos con ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afpnet_mapeos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->string('clave_interna');
            $table->string('codigo_afpnet', 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['tipo', 'clave_interna'], 'afpnet_mapeos_unico');
        });

        $filas = [
            ['tipo' => 'tipo_documento', 'clave_interna' => 'dni', 'codigo_afpnet' => '0'],
            ['tipo' => 'tipo_documento', 'clave_interna' => 'ce', 'codigo_afpnet' => '1'],
            ['tipo' => 'tipo_documento', 'clave_interna' => 'pasaporte', 'codigo_afpnet' => '4'],

            ['tipo' => 'afp', 'clave_interna' => 'habitat', 'codigo_afpnet' => 'HA'],
            ['tipo' => 'afp', 'clave_interna' => 'integra', 'codigo_afpnet' => 'IN'],
            ['tipo' => 'afp', 'clave_interna' => 'profuturo', 'codigo_afpnet' => 'PR'],
            ['tipo' => 'afp', 'clave_interna' => 'prima', 'codigo_afpnet' => 'RI'],
        ];

        $ahora = now();
        foreach ($filas as &$fila) {
            $fila['created_at'] = $ahora;
            $fila['updated_at'] = $ahora;
        }
        unset($fila);

        DB::table('afpnet_mapeos')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('afpnet_mapeos');
    }
};
