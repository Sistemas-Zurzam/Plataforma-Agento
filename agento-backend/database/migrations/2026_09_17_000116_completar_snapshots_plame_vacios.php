<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repara lineas calculadas cuando el concepto todavia no tenia codigo.
     * Los snapshots ya clasificados y las definiciones especificas quedan
     * intactos para conservar su trazabilidad historica.
     */
    public function up(): void
    {
        DB::table('conceptos_remuneracion')
            ->whereNotNull('codigo_plame')
            ->select(['id', 'codigo_plame'])
            ->orderBy('id')
            ->each(function (object $concepto): void {
                DB::table('boleta_conceptos')
                    ->where('concepto_id', $concepto->id)
                    ->whereNull('concepto_definicion_id')
                    ->whereNull('codigo_plame_snapshot')
                    ->update(['codigo_plame_snapshot' => $concepto->codigo_plame]);
            });
    }

    public function down(): void
    {
        // No se revierte: no es posible distinguir con seguridad un NULL
        // reparado de uno registrado posteriormente sin destruir historia.
    }
};
