<?php

namespace App\Console\Commands;

use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Solo para pruebas manuales del webhook de estado de motorizado (ver
 * NotificarEstadoMotorizadoZazuService) — borra las marcaciones y el
 * resultado diario de un colaborador en una fecha, para poder repetir el
 * escaneo de carnet desde cero sin dejar residuos de la vuelta anterior.
 *
 * Bloqueado fuera de local/testing a propósito: borra asistencia real, y
 * un typo en el id de colaborador en producción sería grave.
 */
class LimpiarAsistenciaPruebaCommand extends Command
{
    protected $signature = 'asistencia:limpiar-prueba {colaborador : ID del colaborador} {fecha=today : Fecha Y-m-d, por defecto hoy}';

    protected $description = 'Borra marcaciones/resultado diario de un colaborador en una fecha (solo para pruebas locales)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Este comando solo puede correr en local/testing.');

            return self::FAILURE;
        }

        $colaboradorId = (int) $this->argument('colaborador');
        $fecha = Carbon::parse($this->argument('fecha'));

        $resultados = AsistenciaResultadoDiario::where('colaborador_id', $colaboradorId)
            ->whereDate('fecha', $fecha)->get();
        $resultados->each->delete();

        $marcaciones = AsistenciaMarcacion::where('colaborador_id', $colaboradorId)
            ->whereDate('marcado_at', $fecha)->get();
        $marcaciones->each->delete();

        $this->info("Colaborador {$colaboradorId} — {$fecha->toDateString()}: {$resultados->count()} resultado(s) y {$marcaciones->count()} marcación(es) borradas.");

        return self::SUCCESS;
    }
}
