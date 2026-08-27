<?php

namespace Database\Seeders;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Prerrequisito de ColaboradorSeeder — un colaborador no puede crearse sin
 * sede, área y horario vigentes para su empresa (ColaboradorService::crear).
 * Usa firstOrCreate en todo: si alguna de las 5 empresas ya tiene sus
 * propias sedes/áreas/horarios creados desde la UI, no se duplica nada.
 */
class SedeAreaHorarioSeeder extends Seeder
{
    private const EMPRESAS = ['Zazu', 'Agento', 'Texajo', 'Overshark', 'Bravos'];

    private const AREAS = ['Operaciones', 'Administración', 'Comercial'];

    public function run(): void
    {
        foreach (self::EMPRESAS as $nombre) {
            $empresa = Empresa::where('nombre_comercial', 'like', "%{$nombre}%")->first();

            if (! $empresa) {
                $this->command?->warn("SedeAreaHorarioSeeder: no se encontró ninguna empresa similar a \"{$nombre}\" — se omite.");

                continue;
            }

            Sede::firstOrCreate(
                ['empresa_id' => $empresa->id, 'codigo' => 'PRINCIPAL'],
                ['nombre' => 'Sede Principal', 'direccion' => 'Dirección no registrada', 'activa' => true],
            );

            foreach (self::AREAS as $nombreArea) {
                Area::firstOrCreate(['empresa_id' => $empresa->id, 'nombre' => $nombreArea]);
            }

            $this->crearHorarioEstandar($empresa);
            $this->crearHorarioHibrido($empresa);
        }
    }

    /**
     * vigencia_desde se fija 5 años atrás para que cubra sin problema
     * colaboradores con antigüedad real de hasta 3 años (ColaboradorService
     * exige que el horario ya esté vigente en la fecha_ingreso).
     */
    private function crearHorarioEstandar(Empresa $empresa): Horario
    {
        $horario = Horario::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Horario Estándar'],
            [
                'tolerancia_minutos' => 10,
                'tipo_turno' => 'normal',
                'cruza_medianoche' => false,
                'vigencia_desde' => Carbon::now()->subYears(5)->startOfYear(),
                'activo' => true,
            ],
        );

        if ($horario->dias()->exists()) {
            return $horario;
        }

        $dias = [
            0 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '17:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            1 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '17:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            2 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '17:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            3 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '17:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            4 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '17:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            5 => ['estado' => 'laborable', 'hora_entrada' => '08:00', 'hora_salida' => '13:00', 'refrigerio_inicio' => null, 'refrigerio_fin' => null],
            6 => ['estado' => 'descanso', 'hora_entrada' => null, 'hora_salida' => null, 'refrigerio_inicio' => null, 'refrigerio_fin' => null],
        ];

        foreach ($dias as $diaSemana => $config) {
            $horario->dias()->create([
                'dia_semana' => $diaSemana,
                'permitir_horas_extra' => $config['estado'] === 'laborable',
                'jornada_nocturna' => false,
                ...$config,
            ]);
        }

        return $horario;
    }

    private function crearHorarioHibrido(Empresa $empresa): Horario
    {
        $horario = Horario::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Horario Híbrido'],
            [
                'tolerancia_minutos' => 15,
                'tipo_turno' => 'normal',
                'cruza_medianoche' => false,
                'vigencia_desde' => Carbon::now()->subYears(5)->startOfYear(),
                'activo' => true,
            ],
        );

        if ($horario->dias()->exists()) {
            return $horario;
        }

        $dias = [
            0 => ['estado' => 'laborable', 'hora_entrada' => '09:00', 'hora_salida' => '18:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            1 => ['estado' => 'laborable', 'hora_entrada' => '09:00', 'hora_salida' => '18:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            2 => ['estado' => 'laborable', 'hora_entrada' => '09:00', 'hora_salida' => '18:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            3 => ['estado' => 'laborable', 'hora_entrada' => '09:00', 'hora_salida' => '18:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            4 => ['estado' => 'laborable', 'hora_entrada' => '09:00', 'hora_salida' => '18:00', 'refrigerio_inicio' => '13:00', 'refrigerio_fin' => '14:00'],
            5 => ['estado' => 'descanso', 'hora_entrada' => null, 'hora_salida' => null, 'refrigerio_inicio' => null, 'refrigerio_fin' => null],
            6 => ['estado' => 'descanso', 'hora_entrada' => null, 'hora_salida' => null, 'refrigerio_inicio' => null, 'refrigerio_fin' => null],
        ];

        foreach ($dias as $diaSemana => $config) {
            $horario->dias()->create([
                'dia_semana' => $diaSemana,
                'permitir_horas_extra' => $config['estado'] === 'laborable',
                'jornada_nocturna' => false,
                ...$config,
            ]);
        }

        return $horario;
    }
}
