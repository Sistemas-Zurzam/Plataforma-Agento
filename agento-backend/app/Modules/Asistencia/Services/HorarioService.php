<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HorarioService
{
    /**
     * Horarios es un catálogo GLOBAL — varias empresas de un mismo grupo
     * comparten los mismos horarios, así que a propósito no filtra por
     * empresa.
     *
     * @return LengthAwarePaginator<int, Horario>
     */
    public function listar(?string $busqueda, ?string $estadoFiltro, int $perPage = 10): LengthAwarePaginator
    {
        return Horario::with('dias')
            ->withCount('colaboradores')
            ->when($busqueda, fn ($query) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->when($estadoFiltro === 'activo', fn ($query) => $query->where('activo', true))
            ->when($estadoFiltro === 'inactivo', fn ($query) => $query->where('activo', false))
            ->orderBy('nombre')
            ->paginate($perPage);
    }

    /**
     * @return array{total: int, activos: int, pendientes: int}
     */
    public function estadisticas(): array
    {
        $base = Horario::query();

        return [
            'total' => (clone $base)->count(),
            'activos' => (clone $base)->where('activo', true)->count(),
            'pendientes' => (clone $base)->whereHas('dias', fn ($query) => $query->where('estado', 'pendiente'))->count(),
        ];
    }

    /**
     * Crea el horario y sus 7 días en una sola transacción. El modal ya
     * envía la configuración semanal completa junto con los datos del
     * horario, así que no hay un paso intermedio de "horario sin días".
     */
    public function crear(Empresa $empresa, array $datos): Horario
    {
        return DB::transaction(function () use ($empresa, $datos) {
            $horario = Horario::create([
                ...$this->datosHorario($datos),
                'empresa_id' => $empresa->id,
                'activo' => true,
            ]);

            $this->guardarDias($horario, $datos['dias']);

            return $horario->load('dias')->loadCount('colaboradores');
        });
    }

    /**
     * Actualiza el horario y sus 7 días en el sitio. Como el catálogo es
     * global (varias empresas de un mismo grupo pueden compartir el mismo
     * horario), el bloqueo por "ya tiene trabajadores asignados" es la única
     * protección real: evita que una empresa cambie en caliente el horario
     * que otra ya está usando — se obliga a duplicar en vez de editar.
     *
     * @throws ValidationException
     */
    public function actualizar(Horario $horario, array $datos): Horario
    {
        if ($horario->asignaciones()->exists()) {
            throw ValidationException::withMessages([
                'horario' => 'Este horario ya tiene trabajadores asignados. Duplícalo para crear una nueva versión.',
            ]);
        }

        return DB::transaction(function () use ($horario, $datos) {
            $horario->update($this->datosHorario($datos));
            $this->guardarDias($horario, $datos['dias']);

            return $horario->load('dias')->loadCount('colaboradores');
        });
    }

    /**
     * empresa_id de la copia queda en la empresa que duplica — es solo
     * informativo (quién lo creó/gestiona), no restringe quién puede usarlo.
     */
    public function duplicar(Empresa $empresa, Horario $horario): Horario
    {
        return DB::transaction(function () use ($empresa, $horario) {
            $copia = Horario::create([
                ...$horario->only([
                    'tolerancia_minutos', 'tipo_turno', 'descripcion',
                    'cruza_medianoche', 'vigencia_desde', 'vigencia_hasta',
                ]),
                'empresa_id' => $empresa->id,
                'nombre' => "{$horario->nombre} (copia)",
                'activo' => true,
            ]);

            $diasCopiados = $horario->dias->map(fn ($dia) => [
                ...$dia->only([
                    'dia_semana', 'estado', 'hora_entrada', 'hora_salida',
                    'refrigerio_inicio', 'refrigerio_fin', 'jornada_nocturna', 'permitir_horas_extra',
                ]),
                'horario_id' => $copia->id,
            ])->all();

            $copia->dias()->createMany($diasCopiados);

            return $copia->load('dias')->loadCount('colaboradores');
        });
    }

    public function cambiarEstado(Horario $horario): Horario
    {
        $horario->update(['activo' => ! $horario->activo]);

        return $horario->load('dias')->loadCount('colaboradores');
    }

    private function datosHorario(array $datos): array
    {
        return collect($datos)->only([
            'nombre', 'tolerancia_minutos', 'tipo_turno', 'descripcion',
            'cruza_medianoche', 'vigencia_desde', 'vigencia_hasta',
        ])->all();
    }

    /**
     * Upsert de los 7 días: como dia_semana es único por horario, se
     * actualiza cada uno de los 7 en el sitio (no se insertan filas nuevas).
     */
    private function guardarDias(Horario $horario, array $dias): void
    {
        foreach ($dias as $dia) {
            $horario->dias()->updateOrCreate(
                ['dia_semana' => $dia['dia_semana']],
                collect($dia)->except('dia_semana')->all(),
            );
        }
    }
}
