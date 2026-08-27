<?php

namespace Database\Seeders;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 10 horarios de ejemplo para el catálogo global de horarios (ver migración
 * 2026_08_26_000058_make_empresa_id_nullable_on_horarios_table): cualquier
 * empresa del grupo puede asignarlos a sus colaboradores, no solo la que
 * queda registrada como creadora. Usa firstOrCreate por "nombre" — ya es la
 * identidad real del catálogo ahora que dejó de estar aislado por empresa —
 * para poder correr el seeder más de una vez sin duplicar.
 */
class HorarioSeeder extends Seeder
{
    public function run(): void
    {
        // empresa_id sigue siendo NOT NULL en la tabla hasta que se corra la
        // migración 000058 pendiente; de todas formas, aun después de esa
        // migración el campo solo queda como dato informativo ("quién lo
        // creó"), así que asignarlo a la primera empresa del sistema es
        // seguro en ambos escenarios.
        $empresaCreadora = Empresa::first();

        if (! $empresaCreadora) {
            $this->command?->warn('HorarioSeeder: no hay ninguna empresa registrada todavía — se omite.');

            return;
        }

        foreach ($this->definiciones() as $definicion) {
            $this->crear($empresaCreadora, $definicion);
        }
    }

    /** @return array<int, array{nombre: string, tolerancia_minutos: int, tipo_turno: string, descripcion: string, cruza_medianoche: bool, dias: array<int, array<string, mixed>>}> */
    private function definiciones(): array
    {
        $finde = $this->diaDescanso();

        return [
            [
                'nombre' => 'Horario Oficina 08:00 - 17:00 (Lun-Vie)',
                'tolerancia_minutos' => 10,
                'tipo_turno' => 'normal',
                'descripcion' => 'Jornada estándar de oficina, 8 horas con una hora de refrigerio.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('08:00', '17:00', '13:00', '14:00'),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Horario Oficina 09:00 - 18:00 (Lun-Vie)',
                'tolerancia_minutos' => 10,
                'tipo_turno' => 'normal',
                'descripcion' => 'Jornada estándar de oficina, entrada más tarde.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('09:00', '18:00', '13:00', '14:00'),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Horario Comercial 10:00 - 19:00 (Lun-Sáb)',
                'tolerancia_minutos' => 15,
                'tipo_turno' => 'normal',
                'descripcion' => 'Tiendas y atención al público, seis días a la semana.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('10:00', '19:00', '13:30', '14:30'),
                    sabado: $this->diaLaborable('10:00', '19:00', '13:30', '14:30'),
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Horario Industrial 07:00 - 16:00 (Lun-Sáb)',
                'tolerancia_minutos' => 10,
                'tipo_turno' => 'normal',
                'descripcion' => 'Planta u operaciones, entrada temprana con sábado medio día.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('07:00', '16:00', '12:00', '13:00'),
                    sabado: $this->diaLaborable('07:00', '11:00'),
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Medio Tiempo Mañana 08:00 - 13:00 (Lun-Vie)',
                'tolerancia_minutos' => 5,
                'tipo_turno' => 'normal',
                'descripcion' => 'Media jornada matutina, sin refrigerio por ser turno corto.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('08:00', '13:00', permitirHorasExtra: false),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Medio Tiempo Tarde 14:00 - 19:00 (Lun-Vie)',
                'tolerancia_minutos' => 5,
                'tipo_turno' => 'normal',
                'descripcion' => 'Media jornada vespertina, sin refrigerio por ser turno corto.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('14:00', '19:00', permitirHorasExtra: false),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Turno Noche 22:00 - 06:00 (Lun-Vie)',
                'tolerancia_minutos' => 15,
                'tipo_turno' => 'nocturno',
                'descripcion' => 'Turno fijo nocturno, cruza medianoche.',
                'cruza_medianoche' => true,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('22:00', '06:00', '02:00', '02:30', nocturno: true),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Horario Remoto Flexible 09:00 - 18:00 (Lun-Vie)',
                'tolerancia_minutos' => 30,
                'tipo_turno' => 'normal',
                'descripcion' => 'Para colaboradores remotos, tolerancia amplia por husos horarios y conexión.',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('09:00', '18:00', '13:00', '14:00'),
                    sabado: $finde,
                    domingo: $finde,
                ),
            ],
            [
                'nombre' => 'Rotativo 12x36 Día 07:00 - 19:00',
                'tolerancia_minutos' => 15,
                'tipo_turno' => 'rotativo',
                'descripcion' => 'Turno de 12 horas diurno. El día de descanso de cada colaborador se declara aparte — el sistema nunca lo adivina (ver dias_descanso_rotativo_por_semana).',
                'cruza_medianoche' => false,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('07:00', '19:00', '13:00', '13:30', permitirHorasExtra: false),
                    sabado: $this->diaLaborable('07:00', '19:00', '13:00', '13:30', permitirHorasExtra: false),
                    domingo: $this->diaLaborable('07:00', '19:00', '13:00', '13:30', permitirHorasExtra: false),
                ),
            ],
            [
                'nombre' => 'Rotativo 12x36 Noche 19:00 - 07:00',
                'tolerancia_minutos' => 15,
                'tipo_turno' => 'rotativo',
                'descripcion' => 'Turno de 12 horas nocturno, cruza medianoche. El día de descanso de cada colaborador se declara aparte — el sistema nunca lo adivina.',
                'cruza_medianoche' => true,
                'dias' => $this->semana(
                    laborable: $this->diaLaborable('19:00', '07:00', '23:00', '23:30', nocturno: true, permitirHorasExtra: false),
                    sabado: $this->diaLaborable('19:00', '07:00', '23:00', '23:30', nocturno: true, permitirHorasExtra: false),
                    domingo: $this->diaLaborable('19:00', '07:00', '23:00', '23:30', nocturno: true, permitirHorasExtra: false),
                ),
            ],
        ];
    }

    private function crear(Empresa $empresaCreadora, array $definicion): void
    {
        $horario = Horario::firstOrCreate(
            ['nombre' => $definicion['nombre']],
            [
                'empresa_id' => $empresaCreadora->id,
                'tolerancia_minutos' => $definicion['tolerancia_minutos'],
                'tipo_turno' => $definicion['tipo_turno'],
                'descripcion' => $definicion['descripcion'],
                'cruza_medianoche' => $definicion['cruza_medianoche'],
                'vigencia_desde' => Carbon::now()->subYears(5)->startOfYear(),
                'activo' => true,
            ],
        );

        if ($horario->dias()->exists()) {
            return;
        }

        foreach ($definicion['dias'] as $diaSemana => $config) {
            $horario->dias()->create(['dia_semana' => $diaSemana, ...$config]);
        }
    }

    /**
     * Lun=0 ... Dom=6, igual convención que dayOfWeekIso-1 en
     * ResolverJornadaDiaria.
     *
     * @return array<int, array<string, mixed>>
     */
    private function semana(array $laborable, array $sabado, array $domingo): array
    {
        return [0 => $laborable, 1 => $laborable, 2 => $laborable, 3 => $laborable, 4 => $laborable, 5 => $sabado, 6 => $domingo];
    }

    private function diaLaborable(
        string $entrada,
        string $salida,
        ?string $refrigerioInicio = null,
        ?string $refrigerioFin = null,
        bool $nocturno = false,
        bool $permitirHorasExtra = true,
    ): array {
        return [
            'estado' => 'laborable',
            'hora_entrada' => $entrada,
            'hora_salida' => $salida,
            'refrigerio_inicio' => $refrigerioInicio,
            'refrigerio_fin' => $refrigerioFin,
            'jornada_nocturna' => $nocturno,
            'permitir_horas_extra' => $permitirHorasExtra,
        ];
    }

    private function diaDescanso(): array
    {
        return [
            'estado' => 'descanso',
            'hora_entrada' => null,
            'hora_salida' => null,
            'refrigerio_inicio' => null,
            'refrigerio_fin' => null,
            'jornada_nocturna' => false,
            'permitir_horas_extra' => false,
        ];
    }
}
