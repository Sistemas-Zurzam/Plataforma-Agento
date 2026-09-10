<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Services\ReporteBonoAsistenciaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ReporteBonoAsistenciaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_porcentajes_y_exclusiones_tienen_prioridad_sobre_recuperacion(): void
    {
        $base = ['dias_efectivos' => 26, 'descansos' => 4, 'tardanzas' => 0, 'faltas_justificadas' => 0,
            'faltas_injustificadas' => 0, 'sin_huellero_completo' => 0, 'incumplimientos_turno' => 0,
            'dias_sin_clasificar' => 0, 'dias_sin_resultado' => 0, 'permisos_por_revisar' => 0];
        foreach ([[0, 0, 0, 100, 0], [1, 0, 0, 50, 50], [2, 0, 0, 0, 50], [1, 1, 0, 0, 0], [1, 0, 3, 0, 0], [0, 0, 4, 0, 0]] as [$justificadas, $faltas, $tardanzas, $inicial, $recuperable]) {
            $resultado = ReporteBonoAsistenciaService::evaluar([...$base, 'faltas_justificadas' => $justificadas,
                'faltas_injustificadas' => $faltas, 'tardanzas' => $tardanzas]);
            $this->assertSame($inicial, $resultado['porcentaje_inicial']);
            $this->assertSame($recuperable, $resultado['porcentaje_recuperable']);
            $this->assertSame('Pendiente', $resultado['aprobacion_gerencia']);
        }
        $this->assertSame('Requiere revisión', ReporteBonoAsistenciaService::evaluar([...$base, 'dias_sin_resultado' => 1])['evaluacion']);
    }

    public function test_incluye_sin_boleta_y_sin_datos_cuenta_trabajo_con_incidencias_y_valida_huellero(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $persona = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $sinDatos = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $persona->area()->withoutGlobalScopes()->update(['nombre' => 'VENTAS']);
        $sinDatos->update(['area_id' => $persona->area_id]);
        $marketing = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01', 'cargo' => 'VENDEDOR']);
        $marketing->area()->withoutGlobalScopes()->update(['nombre' => 'MARKETING']);
        $futuro = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-10-01']);
        $otraEmpresa = Empresa::create(['nombre_comercial' => 'Otra empresa', 'razon_social' => 'Otra', 'ruc' => '20999999991', 'activa' => true]);
        $ajeno = $this->crearColaborador($otraEmpresa, ['fecha_ingreso' => '2026-01-01']);
        for ($dia = 1; $dia <= 30; $dia++) {
            $fecha = Carbon::create(2026, 9, $dia);
            $trabajo = $dia <= 26;
            $resultado = AsistenciaResultadoDiario::create(['empresa_id' => $empresa->id, 'colaborador_id' => $persona->id,
                'fecha' => $fecha, 'tipo_dia' => $trabajo ? 'laborable_presencial' : 'descanso',
                'estado' => ! $trabajo ? 'descanso' : ($dia === 1 ? 'horario_desplazado' : 'presente'),
                'entrada_at' => $trabajo ? $fecha->copy()->setTime(9, 0) : null,
                'salida_at' => $trabajo ? $fecha->copy()->setTime(18, 0) : null,
                'minutos_trabajados' => $trabajo ? 480 : 0, 'minutos_tardanza' => $dia === 1 ? 5 : 0, 'procesado_at' => now()]);
            if ($trabajo) foreach ([9, 18] as $hora) {
                $marca = AsistenciaMarcacion::create(['empresa_id' => $empresa->id, 'colaborador_id' => $persona->id,
                    'person_id' => $persona->numero_documento, 'marcado_at' => $fecha->copy()->setTime($hora, 0),
                    'origen' => $dia === 2 ? 'manual_rrhh' : 'attendance_device']);
                $resultado->marcaciones()->attach($marca->id);
            }
        }
        $reporte = app(ReporteBonoAsistenciaService::class)->generar($empresa, '2026-09');
        $filas = collect($reporte['colaboradores'])->keyBy('colaborador_id');
        $this->assertTrue($filas->has($sinDatos->id));
        $this->assertFalse($filas->has($marketing->id));
        $this->assertFalse($filas->has($futuro->id));
        $this->assertFalse($filas->has($ajeno->id));
        $this->assertSame(26, $filas[$persona->id]['dias_efectivos']);
        $this->assertSame(4, $filas[$persona->id]['descansos']);
        $this->assertSame(1, $filas[$persona->id]['tardanzas']);
        $this->assertSame(1, $filas[$persona->id]['sin_huellero_completo']);
        $this->assertSame(30, $filas[$sinDatos->id]['dias_sin_resultado']);
        $marketingReporte = app(ReporteBonoAsistenciaService::class)->generar($empresa, '2026-09', $marketing->area_id);
        $this->assertSame($marketing->id, $marketingReporte['colaboradores'][0]['colaborador_id']);
        $this->assertSame('Requiere revisión', $filas[$persona->id]['evaluacion']);
        $this->assertDatabaseCount('planillas_complementarias', 0);
    }

    public function test_marcacion_incompleta_con_entrada_cuenta_como_dia_efectivo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $persona = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $persona->area()->withoutGlobalScopes()->update(['nombre' => 'VENTAS']);
        for ($dia = 1; $dia <= 30; $dia++) {
            $fecha = Carbon::create(2026, 9, $dia);
            $trabajo = $dia <= 26;
            $esIncompleto = $dia === 15;
            $resultado = AsistenciaResultadoDiario::create(['empresa_id' => $empresa->id, 'colaborador_id' => $persona->id,
                'fecha' => $fecha, 'tipo_dia' => $trabajo ? 'laborable_presencial' : 'descanso',
                'estado' => ! $trabajo ? 'descanso' : ($esIncompleto ? 'marcacion_incompleta' : 'presente'),
                'entrada_at' => $trabajo ? $fecha->copy()->setTime(9, 0) : null,
                'salida_at' => $trabajo && ! $esIncompleto ? $fecha->copy()->setTime(18, 0) : null,
                'minutos_trabajados' => $trabajo && ! $esIncompleto ? 480 : 0, 'minutos_tardanza' => 0, 'procesado_at' => now()]);
            // Marcaciones reales del huellero en los días completos, para que
            // "sin_huellero_completo" solo detecte el día 15 (marcación
            // incompleta) y no marque también los 25 días con entrada y
            // salida ya registradas por biométrico.
            if ($trabajo) foreach ($esIncompleto ? [9] : [9, 18] as $hora) {
                $marca = AsistenciaMarcacion::create(['empresa_id' => $empresa->id, 'colaborador_id' => $persona->id,
                    'person_id' => $persona->numero_documento, 'marcado_at' => $fecha->copy()->setTime($hora, 0),
                    'origen' => 'attendance_device']);
                $resultado->marcaciones()->attach($marca->id);
            }
        }
        $reporte = app(ReporteBonoAsistenciaService::class)->generar($empresa, '2026-09');
        $fila = collect($reporte['colaboradores'])->firstWhere('colaborador_id', $persona->id);
        // El colaborador sí llegó el día 15 (hay entrada_at) aunque le falte la
        // marca de salida: debe seguir contando para las 26 jornadas del bono,
        // no como "día sin clasificar" — pero sí como observación a revisar.
        $this->assertSame(26, $fila['dias_efectivos']);
        $this->assertSame(0, $fila['dias_sin_clasificar']);
        $this->assertSame(1, $fila['sin_huellero_completo']);
    }
}
