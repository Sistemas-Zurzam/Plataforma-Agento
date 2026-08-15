<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HorarioService
{
    /**
     * @return LengthAwarePaginator<int, Horario>
     */
    public function listar(Empresa $empresa, ?string $busqueda, ?string $estadoFiltro, int $perPage = 10): LengthAwarePaginator
    {
        return Horario::where('empresa_id', $empresa->id)
            ->with('dias')
            ->when($busqueda, fn ($query) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->when($estadoFiltro === 'activo', fn ($query) => $query->where('activo', true))
            ->when($estadoFiltro === 'inactivo', fn ($query) => $query->where('activo', false))
            ->orderBy('nombre')
            ->paginate($perPage);
    }

    /**
     * @return array{total: int, activos: int, pendientes: int}
     */
    public function estadisticas(Empresa $empresa): array
    {
        $base = Horario::where('empresa_id', $empresa->id);

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

            return $horario->load('dias');
        });
    }

    /**
     * Actualiza el horario y sus 7 días en el sitio. A diferencia de
     * Parámetros Laborales / Comisiones AFP (valores legales que alimentan
     * cálculos históricos), un horario es una plantilla reutilizable: no
     * hay historial que proteger, así que se sobrescribe directamente.
     *
     * @throws AuthorizationException
     */
    public function actualizar(Empresa $empresa, Horario $horario, array $datos): Horario
    {
        $this->verificarPertenencia($empresa, $horario);

        return DB::transaction(function () use ($horario, $datos) {
            $horario->update($this->datosHorario($datos));
            $this->guardarDias($horario, $datos['dias']);

            return $horario->load('dias');
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function duplicar(Empresa $empresa, Horario $horario): Horario
    {
        $this->verificarPertenencia($empresa, $horario);

        return DB::transaction(function () use ($horario) {
            $copia = Horario::create([
                ...$horario->only([
                    'empresa_id', 'tolerancia_minutos', 'tipo_turno', 'descripcion',
                    'cruza_medianoche', 'vigencia_desde', 'vigencia_hasta',
                ]),
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

            return $copia->load('dias');
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function cambiarEstado(Empresa $empresa, Horario $horario): Horario
    {
        $this->verificarPertenencia($empresa, $horario);

        $horario->update(['activo' => ! $horario->activo]);

        return $horario->load('dias');
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

    private function verificarPertenencia(Empresa $empresa, Horario $horario): void
    {
        if ($horario->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este horario no pertenece a la empresa activa.');
        }
    }
}
