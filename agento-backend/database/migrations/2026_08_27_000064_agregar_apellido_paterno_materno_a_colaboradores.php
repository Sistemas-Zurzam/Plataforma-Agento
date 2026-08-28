<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las estructuras E4/E7 de PLAME (Anexo 3 SUNAT) exigen apellido paterno y
 * materno como campos independientes — "apellidos" hoy los guarda juntos
 * en un solo string.
 *
 * A propósito NO se hace un split automático (explode(' ', $apellidos)):
 * apellidos compuestos peruanos ("DE LA CRUZ RAMOS", "PONCE DE LEÓN
 * GARCÍA") hacen que separar por espacios sea incorrecto con frecuencia.
 * Por eso las columnas nuevas quedan NULL para todo colaborador existente
 * — es exactamente la señal de "necesita revisión manual" (se completa al
 * editar el colaborador), sin inventar un valor incorrecto ni perder el
 * dato original (`apellidos` se conserva intacto y sigue siendo la fuente
 * para todo lo que no se ha migrado a leer los campos separados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('apellido_paterno', 100)->nullable()->after('nombres');
            $table->string('apellido_materno', 100)->nullable()->after('apellido_paterno');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn(['apellido_paterno', 'apellido_materno']);
        });
    }
};
