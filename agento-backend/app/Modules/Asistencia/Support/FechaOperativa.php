<?php

namespace App\Modules\Asistencia\Support;

use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4A.2 — fecha operativa de las reglas de negocio de Asistencia
 * (cobertura, cierre de período, "¿es una fecha futura?"): deliberadamente
 * distinta del timezone TÉCNICO de la app (config('app.timezone'), UTC).
 * Base de datos, timestamps, colas y JWT siguen en UTC sin cambios — esto
 * solo resuelve "qué día es hoy" para reglas laborales que dependen de la
 * fecha calendario real de la operación (hoy, Perú).
 *
 * Única fuente de verdad: no repetir config('asistencia.operational_timezone')
 * ni 'America/Lima' sueltos en otros servicios — usar esta clase.
 *
 * Sin dependencias de auth()/request(): se resuelve igual desde un
 * controller que desde un queue worker (AsegurarCoberturaAsistenciaPeriodoJob
 * corre sin sesión HTTP).
 */
class FechaOperativa
{
    public function ahora(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function hoy(): Carbon
    {
        return $this->ahora()->startOfDay();
    }

    /**
     * Un timezone IANA inválido en .env no debe romper cada cierre de
     * período con una excepción de DateTimeZone — se registra como error
     * crítico y se opera con el fallback seguro en su lugar.
     */
    private function timezone(): string
    {
        $configurado = config('asistencia.operational_timezone', 'America/Lima');

        if (in_array($configurado, DateTimeZone::listIdentifiers(), true)) {
            return $configurado;
        }

        Log::critical("FechaOperativa: timezone operativo \"{$configurado}\" no es un identificador IANA válido — usando America/Lima como fallback.");

        return 'America/Lima';
    }
}
