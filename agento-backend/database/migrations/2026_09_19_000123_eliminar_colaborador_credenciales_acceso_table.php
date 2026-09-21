<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión de negocio: el código de barras del carnet ES el número de
 * documento del colaborador, sin credencial generada/revocable por
 * separado — ver RegistrarMarcacionCarnetService::registrar(). La tabla de
 * la migración create_colaborador_credenciales_acceso_table ya no tiene
 * ningún lector (CarnetCredentialService/CredencialAcceso se eliminaron),
 * así que se da de baja acá en vez de editar esa migración ya aplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('colaborador_credenciales_acceso');
    }

    public function down(): void
    {
        // No se recrea: la migración original sigue existiendo en el
        // historial (create_colaborador_credenciales_acceso_table) para
        // quien necesite revertir hasta ese punto.
    }
};
