<?php

namespace Database\Seeders;

use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use Illuminate\Database\Seeder;

class ParametroLaboralDefinicionSeeder extends Seeder
{
    /**
     * Catálogo fijo de parámetros laborales (definido por código, igual que
     * Permission): el admin solo registra valores por régimen/vigencia para
     * estas claves, no crea claves nuevas desde la UI. Agrupado en 5
     * secciones para el despliegue de las pantallas Ver/Editar.
     */
    public function run(): void
    {
        $definiciones = [
            // Valores Base
            ['clave' => 'rmv', 'grupo' => 'Valores Base', 'nombre' => 'RMV', 'unidad' => 'S/', 'orden' => 1],
            ['clave' => 'uit', 'grupo' => 'Valores Base', 'nombre' => 'UIT', 'unidad' => 'S/', 'orden' => 2],
            ['clave' => 'vacaciones_dias', 'grupo' => 'Valores Base', 'nombre' => 'Días de Vacaciones', 'unidad' => 'días', 'orden' => 3],

            // Aportes y Tasas
            ['clave' => 'essalud_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'EsSalud', 'unidad' => '%', 'orden' => 4],
            ['clave' => 'sis_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'SIS', 'unidad' => '%', 'orden' => 5],
            ['clave' => 'onp_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'ONP', 'unidad' => '%', 'orden' => 6],
            ['clave' => 'afp_aporte_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'AFP Aporte', 'unidad' => '%', 'orden' => 7],
            ['clave' => 'afp_prima_seguro_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'AFP Prima Seguro', 'unidad' => '%', 'orden' => 8],
            ['clave' => 'asignacion_familiar_porcentaje', 'grupo' => 'Aportes y Tasas', 'nombre' => 'Asignación Familiar', 'unidad' => '%', 'orden' => 9],

            // Beneficios Laborales
            ['clave' => 'gratificacion_porcentaje', 'grupo' => 'Beneficios Laborales', 'nombre' => 'Gratificación', 'unidad' => '%', 'orden' => 10],
            ['clave' => 'cts_porcentaje', 'grupo' => 'Beneficios Laborales', 'nombre' => 'CTS', 'unidad' => '%', 'orden' => 11],
            ['clave' => 'bonificacion_extraordinaria_porcentaje', 'grupo' => 'Beneficios Laborales', 'nombre' => 'Bonificación Extraordinaria', 'unidad' => '%', 'orden' => 12],

            // Horas Extra
            ['clave' => 'horas_extra_limite_25_h', 'grupo' => 'Horas Extra', 'nombre' => 'Límite 25% (horas)', 'unidad' => 'horas', 'orden' => 13],
            ['clave' => 'horas_extra_limite_35_h', 'grupo' => 'Horas Extra', 'nombre' => 'Límite 35% (horas)', 'unidad' => 'horas', 'orden' => 14],
            ['clave' => 'horas_extra_tasa_x25', 'grupo' => 'Horas Extra', 'nombre' => 'Tasa primeras horas', 'unidad' => 'x', 'orden' => 15],
            ['clave' => 'horas_extra_tasa_x35', 'grupo' => 'Horas Extra', 'nombre' => 'Tasa siguientes horas', 'unidad' => 'x', 'orden' => 16],
            ['clave' => 'horas_extra_tasa_nocturna', 'grupo' => 'Horas Extra', 'nombre' => 'Tasa nocturna/feriado', 'unidad' => 'x', 'orden' => 17],

            // Renta
            ['clave' => 'renta_4ta_tasa', 'grupo' => 'Renta', 'nombre' => 'Renta 4ta (tasa)', 'unidad' => '%', 'orden' => 18],
            ['clave' => 'renta_4ta_umbral', 'grupo' => 'Renta', 'nombre' => 'Umbral Renta 4ta', 'unidad' => 'S/', 'orden' => 19],
            ['clave' => 'deduccion_5ta_uit', 'grupo' => 'Renta', 'nombre' => 'Deducción 5ta', 'unidad' => 'UIT', 'orden' => 20],
        ];

        foreach ($definiciones as $definicion) {
            ParametroLaboralDefinicion::updateOrCreate(['clave' => $definicion['clave']], $definicion);
        }
    }
}
