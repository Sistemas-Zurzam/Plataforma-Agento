<?php

namespace App\Modules\Personas\Services;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ColaboradorService
{
    /**
     * @return LengthAwarePaginator<int, Colaborador>
     */
    public function listar(Empresa $empresa, ?string $busqueda, int $perPage = 10): LengthAwarePaginator
    {
        return Colaborador::where('empresa_id', $empresa->id)
            ->with(['sede', 'area', 'horario', 'remuneraciones' => fn ($query) => $query->limit(1)])
            ->when($busqueda, fn ($query) => $query->where(function ($query) use ($busqueda) {
                $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('legajo', 'like', "%{$busqueda}%");
            }))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array{total: int, activos: int}
     */
    public function estadisticas(Empresa $empresa): array
    {
        $base = Colaborador::where('empresa_id', $empresa->id);

        return [
            'total' => (clone $base)->count(),
            'activos' => (clone $base)->where('activo', true)->count(),
        ];
    }

    /**
     * Arma el calendario del mes de `fechaIngreso` con un tipo por defecto
     * por día: feriado oficial (fijo, no editable) > día de semana en
     * "descanso" según el horario > laborable presencial. Los días
     * anteriores a fechaIngreso quedan marcados como no editables (el
     * frontend no los envía al crear el colaborador).
     *
     * @return array{dias: array<int, array{fecha: string, tipo: string, editable: bool}>}
     *
     * @throws AuthorizationException
     */
    public function calendarioPorDefecto(Empresa $empresa, Horario $horario, string $fechaIngreso): array
    {
        if ($horario->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El horario indicado no pertenece a la empresa activa.');
        }

        $horario->loadMissing('dias');
        $diasPorSemana = $horario->dias->keyBy('dia_semana');

        $fechaIngresoCarbon = Carbon::parse($fechaIngreso)->startOfDay();
        $inicioMes = $fechaIngresoCarbon->copy()->startOfMonth();
        $finMes = $fechaIngresoCarbon->copy()->endOfMonth();

        $dias = [];
        for ($fecha = $inicioMes->copy(); $fecha->lte($finMes); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();

            if (FeriadosPeru::esFeriado($fechaTexto)) {
                $tipo = 'feriado';
            } else {
                // dia_semana en Horario: 0=Lunes...6=Domingo; dayOfWeekIso: 1=Lunes...7=Domingo.
                $horarioDia = $diasPorSemana->get($fecha->dayOfWeekIso - 1);
                $tipo = ($horarioDia && $horarioDia->estado === 'descanso') ? 'descanso' : 'laborable_presencial';
            }

            $dias[] = [
                'fecha' => $fechaTexto,
                'tipo' => $tipo,
                'editable' => $fecha->gte($fechaIngresoCarbon),
            ];
        }

        return ['dias' => $dias];
    }

    /**
     * Crea el colaborador, su primera remuneración y su calendario inicial
     * en una sola transacción. Verifica que sede/área/horario elegidos
     * pertenezcan a la misma empresa activa (mismo patrón que
     * SedeService/HorarioService).
     *
     * @throws AuthorizationException
     */
    public function crear(Empresa $empresa, array $datos): Colaborador
    {
        $this->verificarPertenenciaDeReferencias($empresa, $datos['sede_id'], $datos['area_id'], $datos['horario_id']);

        return DB::transaction(function () use ($empresa, $datos) {
            $colaborador = Colaborador::create([
                ...collect($datos)
                    ->except(['salario', 'moneda_salario', 'periodicidad_pago', 'asignacion_familiar', 'calendario'])
                    ->all(),
                'empresa_id' => $empresa->id,
                'legajo' => $this->siguienteLegajo($empresa),
            ]);

            $colaborador->remuneraciones()->create([
                'salario' => $datos['salario'],
                'moneda_salario' => $datos['moneda_salario'],
                'periodicidad_pago' => $datos['periodicidad_pago'],
                'asignacion_familiar' => $datos['asignacion_familiar'] ?? 0,
                'vigencia_desde' => $datos['fecha_ingreso'],
            ]);

            // Se descarta cualquier día anterior a fecha_ingreso por
            // defensividad, aunque el frontend ya no debería enviarlos.
            collect($datos['calendario'] ?? [])
                ->filter(fn (array $dia) => $dia['fecha'] >= $datos['fecha_ingreso'])
                ->each(fn (array $dia) => $colaborador->calendario()->create([
                    'fecha' => $dia['fecha'],
                    'tipo' => $dia['tipo'],
                ]));

            return $colaborador->load(['sede', 'area', 'horario', 'remuneraciones', 'calendario']);
        });
    }

    private function siguienteLegajo(Empresa $empresa): string
    {
        $ultimoNumero = Colaborador::where('empresa_id', $empresa->id)
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(legajo, '-', -1) AS UNSIGNED)) as maximo")
            ->value('maximo');

        return 'LEG-'.str_pad((int) $ultimoNumero + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @throws AuthorizationException
     */
    private function verificarPertenenciaDeReferencias(Empresa $empresa, int $sedeId, int $areaId, int $horarioId): void
    {
        $sedeValida = Sede::where('id', $sedeId)->where('empresa_id', $empresa->id)->exists();
        $areaValida = Area::where('id', $areaId)->where('empresa_id', $empresa->id)->exists();
        $horarioValido = Horario::where('id', $horarioId)->where('empresa_id', $empresa->id)->exists();

        if (! $sedeValida || ! $areaValida || ! $horarioValido) {
            throw new AuthorizationException('La sede, área u horario indicados no pertenecen a la empresa activa.');
        }
    }
}
