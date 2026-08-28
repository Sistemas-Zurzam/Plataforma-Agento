<?php

namespace Database\Seeders;

use App\Modules\Asistencia\Models\TipoAusencia;
use Illuminate\Database\Seeder;

/**
 * updateOrCreate (no create): la migración 2026_08_27_000066 ya inserta
 * estas mismas filas directamente para que un simple `php artisan migrate`
 * deje el catálogo completo sin depender de que alguien corra los
 * seeders — este seeder solo existe por paridad con AfpSeeder/
 * ConceptoRemuneracionSeeder para `migrate:fresh --seed`, y debe poder
 * re-ejecutarse sin duplicar ni pisar la clasificación SUNAT si ya se
 * cargó (ver migración 000077 para la clasificación real).
 */
class TipoAusenciaSeeder extends Seeder
{
    private const TIPOS = [
        ['codigo' => 'vacaciones', 'nombre' => 'Vacaciones', 'afecta_asistencia' => true, 'remunerada' => true, 'codigo_sunat_suspension' => '23', 'descripcion_sunat' => 'S.I. Descanso vacacional (Anexo 2, Tabla 21)'],
        [
            'codigo' => 'medico', 'nombre' => 'Descanso médico / permiso médico', 'afecta_asistencia' => true, 'remunerada' => null,
            'sunat_bloqueado_por_modelo' => true,
            'sunat_motivo_estado' => 'Tabla 21 distingue código 20 (primeros 20 días, a cargo del empleador) de código 21 (subsidiado por EsSalud desde el día 21) — Agento registra "medico" sin ese corte de fecha.',
        ],
        [
            'codigo' => 'personal', 'nombre' => 'Permiso personal', 'afecta_asistencia' => true, 'remunerada' => null,
            'sunat_bloqueado_por_modelo' => true,
            'sunat_motivo_estado' => 'Tabla 21 distingue código 05 (sin goce de haber) de código 26 (con goce de haber) — Agento registra "personal" con remunerada=NULL (varía caso a caso) y no captura ese dato por permiso individual.',
        ],
        [
            'codigo' => 'capacitacion', 'nombre' => 'Capacitación', 'afecta_asistencia' => true, 'remunerada' => null,
            'sunat_bloqueado_por_modelo' => true,
            'sunat_motivo_estado' => 'Tabla 21 no tiene un código específico para "capacitación" — corresponde a 05 (sin goce) o 26 (con goce) según si se pagó o no, dato que Agento no distingue para este tipo.',
        ],
        [
            'codigo' => 'comision_servicio', 'nombre' => 'Comisión de servicio', 'afecta_asistencia' => true, 'remunerada' => null,
            'sunat_no_aplica' => true,
            'sunat_motivo_estado' => 'Una "comisión de servicio" implica que el colaborador sigue trabajando (solo cambia de sede/labor), no una suspensión de la relación laboral — no corresponde declarar un día no laborado en Tabla 21 mientras Agento lo use con ese sentido.',
        ],
        [
            'codigo' => 'otro', 'nombre' => 'Otro', 'afecta_asistencia' => true, 'remunerada' => null,
            'sunat_motivo_estado' => 'Cada ausencia registrada como "otro" requiere que RR.HH. determine su causa real antes de poder declararla en un futuro .snl — no tiene una única equivalencia posible en la Tabla 21.',
        ],
        ['codigo' => 'falta_injustificada', 'nombre' => 'Falta injustificada', 'afecta_asistencia' => true, 'remunerada' => false, 'codigo_sunat_suspension' => '07', 'descripcion_sunat' => 'S.P. Falta no justificada (Anexo 2, Tabla 21)'],
    ];

    public function run(): void
    {
        foreach (self::TIPOS as $tipo) {
            $registro = TipoAusencia::updateOrCreate(
                ['codigo' => $tipo['codigo']],
                [
                    'nombre' => $tipo['nombre'],
                    'afecta_asistencia' => $tipo['afecta_asistencia'],
                    'remunerada' => $tipo['remunerada'],
                    'activo' => true,
                ],
            );

            // codigo_sunat_suspension/descripcion_sunat/sunat_* solo se
            // completan en la creación o si todavía están vacíos — nunca
            // se pisa una clasificación SUNAT ya cargada (ver comentario
            // de clase).
            if (isset($tipo['codigo_sunat_suspension']) && blank($registro->codigo_sunat_suspension)) {
                $registro->update([
                    'codigo_sunat_suspension' => $tipo['codigo_sunat_suspension'],
                    'descripcion_sunat' => $tipo['descripcion_sunat'] ?? null,
                ]);
            }
            if (isset($tipo['sunat_motivo_estado']) && blank($registro->sunat_motivo_estado)) {
                $registro->update([
                    'sunat_no_aplica' => $tipo['sunat_no_aplica'] ?? false,
                    'sunat_bloqueado_por_modelo' => $tipo['sunat_bloqueado_por_modelo'] ?? false,
                    'sunat_motivo_estado' => $tipo['sunat_motivo_estado'],
                ]);
            }
        }
    }
}
