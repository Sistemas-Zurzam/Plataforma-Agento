<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * V3 Fase 3 — normaliza el bug histórico donde resolverIncidencia() y
     * resolverHoraExtra() guardaban el verbo crudo de la acción
     * ('rechazar') en vez del estado final adjetivado ('rechazada'/
     * 'rechazado'). La equivalencia es inequívoca: 'rechazar' nunca fue un
     * estado válido intencional en ninguna de las dos tablas (no aparece en
     * ningún Rule::in/enum de la aplicación, solo pudo llegar ahí por este
     * bug puntual) — no se trata de una reinterpretación de negocio, es
     * corregir un typo persistido. Ambas tablas usan géneros distintos a
     * propósito (AsistenciaIncidencia ya usaba 'resuelta'/'rechazada';
     * AsistenciaHoraExtra ya usaba 'aprobado', así que su par correcto es
     * 'rechazado'), por eso son dos UPDATE separados, no uno genérico.
     */
    public function up(): void
    {
        DB::table('asistencia_incidencias')->where('estado', 'rechazar')->update(['estado' => 'rechazada']);
        DB::table('asistencia_horas_extra')->where('estado', 'rechazar')->update(['estado' => 'rechazado']);
    }

    /**
     * No hay forma segura de distinguir, entre las filas ya normalizadas a
     * 'rechazada'/'rechazado', cuáles venían del bug 'rechazar' y cuáles ya
     * habían sido escritas correctamente por algún otro camino — por eso
     * down() no revierte el dato (evita corromper filas correctas), solo
     * deja constancia de que la reversión de datos no es posible acá.
     */
    public function down(): void {}
};
