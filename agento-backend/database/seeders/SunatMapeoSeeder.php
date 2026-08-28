<?php

namespace Database\Seeders;

use App\Modules\Nominas\Models\SunatMapeo;
use Illuminate\Database\Seeder;

/**
 * firstOrCreate (no updateOrCreate): la migración 2026_08_27_000071 ya
 * inserta estas mismas filas directamente para que un simple
 * `php artisan migrate` deje el catálogo completo, y las migraciones
 * 2026_08_27_000074/000075/000077 cargan los códigos y la clasificación
 * confirmados por evidencia directa del Anexo 2/3 — este seeder solo existe
 * por paridad con TipoAusenciaSeeder para `migrate:fresh --seed`, y nunca
 * toca codigo_sunat/descripcion_sunat/activo/motivo_estado si la fila ya
 * existe, para no pisar un mapeo que el administrador ya configuró
 * manualmente.
 *
 * Los valores por defecto repiten lo que cargan esas migraciones,
 * únicamente para que una base de datos NUEVA (sin haber pasado por ellas)
 * también quede correctamente clasificada.
 */
class SunatMapeoSeeder extends Seeder
{
    private const MAPEOS = [
        ['tipo' => 'tipo_documento', 'clave_interna' => 'dni', 'codigo_sunat' => '01', 'descripcion_sunat' => 'Documento Nacional de Identidad'],
        ['tipo' => 'tipo_documento', 'clave_interna' => 'ce', 'codigo_sunat' => '04', 'descripcion_sunat' => 'Carné de Extranjería'],
        ['tipo' => 'tipo_documento', 'clave_interna' => 'pasaporte', 'codigo_sunat' => '07', 'descripcion_sunat' => 'Pasaporte'],
        [
            'tipo' => 'tipo_trabajador', 'clave_interna' => 'trabajador', 'bloqueado_por_modelo' => true,
            'motivo_estado' => 'Agento utiliza "trabajador" de forma genérica, mientras SUNAT distingue Empleado (código 21) y Obrero (código 20) en la Tabla 8 — hace falta que Personas capture esa distinción antes de poder asignar un código único.',
        ],
        [
            'tipo' => 'tipo_trabajador', 'clave_interna' => 'practicante', 'activo' => false,
            'motivo_estado' => 'No aplica a Tabla 8 vía E5: el personal en formación - modalidad formativa laboral se declara mediante la Estructura 9 (T-Registro), separada de "trabajador".',
        ],
        [
            'tipo' => 'tipo_trabajador', 'clave_interna' => 'locador', 'activo' => false,
            'motivo_estado' => 'No aplica a Tabla 8: los prestadores de 4ta categoría se declaran mediante las estructuras E7 (.ps4) y E20 (.4ta), que no tienen campo "tipo de trabajador".',
        ],
        ['tipo' => 'regimen_laboral', 'clave_interna' => 'General', 'codigo_sunat' => '01', 'descripcion_sunat' => 'Privado General - Decreto Legislativo N.° 728 (Anexo 2, Tabla 33)'],
        ['tipo' => 'regimen_laboral', 'clave_interna' => 'Micro Empresa', 'codigo_sunat' => '16', 'descripcion_sunat' => 'Microempresa D. Leg. 1086, aplicable solo si inscrita en REMYPE (Anexo 2, Tabla 33)'],
        ['tipo' => 'regimen_laboral', 'clave_interna' => 'Pequeña Empresa', 'codigo_sunat' => '17', 'descripcion_sunat' => 'Pequeña Empresa D. Leg. 1086, aplicable solo si inscrita en REMYPE (Anexo 2, Tabla 33)'],
        [
            'tipo' => 'regimen_laboral', 'clave_interna' => 'Locacion de Servicios', 'activo' => false,
            'motivo_estado' => 'No aplica a Tabla 33: la tabla completa (Anexo 2) no contiene ningún código de "régimen laboral" para contratación civil/locación de servicios.',
        ],
        [
            'tipo' => 'tipo_comprobante_rh', 'clave_interna' => 'recibo_honorarios', 'codigo_sunat' => 'R',
            'descripcion_sunat' => 'Recibo por Honorarios (Anexo 2, Tabla 23).',
        ],
    ];

    public function run(): void
    {
        foreach (self::MAPEOS as $mapeo) {
            SunatMapeo::firstOrCreate(
                ['tipo' => $mapeo['tipo'], 'clave_interna' => $mapeo['clave_interna']],
                [
                    'activo' => $mapeo['activo'] ?? true,
                    'codigo_sunat' => $mapeo['codigo_sunat'] ?? null,
                    'descripcion_sunat' => $mapeo['descripcion_sunat'] ?? null,
                    'bloqueado_por_modelo' => $mapeo['bloqueado_por_modelo'] ?? false,
                    'motivo_estado' => $mapeo['motivo_estado'] ?? null,
                ],
            );
        }
    }
}
