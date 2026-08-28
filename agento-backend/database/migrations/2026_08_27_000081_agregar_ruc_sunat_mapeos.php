<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agento ahora permite tipo_documento="ruc" para locadores (Colaborador
     * en régimen "Locacion de Servicios") — decisión funcional: se
     * representa como un valor más de tipo_documento en el Colaborador
     * existente, sin crear una entidad "Proveedor"/"Persona Jurídica"
     * separada, porque el dominio actual ya modela al locador como
     * Colaborador y no hay necesidad real de desacoplarlo. Tabla 3 confirma
     * el código 06 exclusivamente para prestadores de servicios.
     */
    public function up(): void
    {
        DB::table('sunat_mapeos')->insert([
            'tipo' => 'tipo_documento', 'clave_interna' => 'ruc',
            'codigo_sunat' => '06', 'descripcion_sunat' => 'RUC — solo prestadores de servicios 4ta categoría (Anexo 2, Tabla 3)',
            'activo' => true, 'bloqueado_por_modelo' => false, 'motivo_estado' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sunat_mapeos')->where('tipo', 'tipo_documento')->where('clave_interna', 'ruc')->delete();
    }
};
