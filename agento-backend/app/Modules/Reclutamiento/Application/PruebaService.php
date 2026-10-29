<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\Prueba;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Banco de pruebas versionadas. Una prueba publicada es inmutable: editarla
 * crea una nueva versión en borrador. Agento no incluye baterías
 * precargadas; "validada" solo se marca con la fuente/criterio que la
 * respalda, registrada por quien la aprueba.
 */
class PruebaService
{
    public function __construct(private readonly RegistroActividad $actividad) {}

    public function listar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return Prueba::where('empresa_id', $alcance->empresaId)
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['busqueda'] ?? null, fn ($q, $texto) => $q->where('nombre', 'like', "%{$texto}%"))
            ->withCount(['preguntas', 'dimensiones'])
            ->orderBy('nombre')
            ->orderByDesc('version')
            ->paginate($perPage);
    }

    public function detalle(Prueba $prueba): Prueba
    {
        return $prueba->load(['dimensiones', 'preguntas.opciones']);
    }

    public function crear(AlcanceSeleccion $alcance, array $datos): Prueba
    {
        return DB::transaction(function () use ($alcance, $datos) {
            $prueba = Prueba::create([
                'empresa_id' => $alcance->empresaId,
                'familia' => (string) Str::uuid(),
                'version' => 1,
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'tipo' => $datos['tipo'],
                'tiempo_limite_minutos' => $datos['tiempo_limite_minutos'],
                'estado' => Prueba::BORRADOR,
                'validada' => false,
                'creado_por' => $alcance->usuario->id,
            ]);
            $this->guardarContenido($prueba, $datos);
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_creada', $alcance->usuario->id);

            return $this->detalle($prueba);
        });
    }

    public function actualizar(AlcanceSeleccion $alcance, Prueba $prueba, array $datos): Prueba
    {
        if ($prueba->estado !== Prueba::BORRADOR) {
            throw new TransicionInvalidaException('Solo se editan pruebas en borrador. Crea una nueva versión para modificar una prueba publicada.');
        }

        return DB::transaction(function () use ($alcance, $prueba, $datos) {
            $prueba->update(collect($datos)->only(['nombre', 'descripcion', 'tipo', 'tiempo_limite_minutos'])->all());
            $prueba->preguntas()->delete();
            $prueba->dimensiones()->delete();
            $this->guardarContenido($prueba, $datos);
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_editada', $alcance->usuario->id);

            return $this->detalle($prueba->refresh());
        });
    }

    public function publicar(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        if ($prueba->estado !== Prueba::BORRADOR) {
            throw new TransicionInvalidaException('Solo se publican pruebas en borrador.');
        }

        $prueba->load(['dimensiones', 'preguntas.opciones']);
        if ($prueba->preguntas->isEmpty()) {
            throw ValidationException::withMessages(['preguntas' => 'La prueba no tiene preguntas.']);
        }
        foreach ($prueba->preguntas as $pregunta) {
            if ($pregunta->opciones->count() < 2 || $pregunta->opciones->max('valor') <= 0) {
                throw ValidationException::withMessages(['preguntas' => 'Cada pregunta necesita al menos dos opciones y una con valor mayor a 0.']);
            }
        }

        DB::transaction(function () use ($alcance, $prueba) {
            // Se archiva la versión publicada anterior de la misma familia.
            Prueba::where('empresa_id', $prueba->empresa_id)->where('familia', $prueba->familia)
                ->where('estado', Prueba::PUBLICADA)->update(['estado' => Prueba::ARCHIVADA]);
            $prueba->update(['estado' => Prueba::PUBLICADA]);
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_publicada', $alcance->usuario->id);
        });

        return $this->detalle($prueba->refresh());
    }

    /**
     * Marca la prueba como instrumento validado. Requiere contenido publicado
     * y la fuente o criterio de validación aprobado (quién, qué estudio o
     * norma lo respalda); queda registrado quién lo aprobó.
     */
    public function validar(AlcanceSeleccion $alcance, Prueba $prueba, string $fuente): Prueba
    {
        if ($prueba->estado !== Prueba::PUBLICADA) {
            throw new TransicionInvalidaException('Solo se puede validar una prueba publicada.');
        }

        $prueba->update([
            'validada' => true,
            'validacion_fuente' => $fuente,
            'validada_por' => $alcance->usuario->id,
            'validada_at' => now(),
        ]);
        $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_validada', $alcance->usuario->id, $fuente);

        return $this->detalle($prueba);
    }

    public function nuevaVersion(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        return DB::transaction(function () use ($alcance, $prueba) {
            $prueba->load(['dimensiones', 'preguntas.opciones']);
            $siguiente = (int) Prueba::where('empresa_id', $prueba->empresa_id)->where('familia', $prueba->familia)->max('version') + 1;

            $copia = Prueba::create([
                ...$prueba->only(['empresa_id', 'familia', 'nombre', 'descripcion', 'tipo', 'tiempo_limite_minutos']),
                'version' => $siguiente,
                'estado' => Prueba::BORRADOR,
                'validada' => false,
                'creado_por' => $alcance->usuario->id,
            ]);

            $this->guardarContenido($copia, [
                'dimensiones' => $prueba->dimensiones->map(fn ($d) => ['clave' => (string) $d->id, 'nombre' => $d->nombre, 'peso' => $d->peso])->all(),
                'preguntas' => $prueba->preguntas->map(fn ($p) => [
                    'dimension' => (string) $p->dimension_id,
                    'enunciado' => $p->enunciado,
                    'peso' => $p->peso,
                    'opciones' => $p->opciones->map(fn ($o) => ['texto' => $o->texto, 'valor' => $o->valor])->all(),
                ])->all(),
            ]);
            $this->actividad->registrar($alcance->empresaId, $copia, 'prueba_versionada', $alcance->usuario->id, null, ['desde_version' => $prueba->version]);

            return $this->detalle($copia);
        });
    }

    public function archivar(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        $prueba->update(['estado' => Prueba::ARCHIVADA]);
        $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_archivada', $alcance->usuario->id);

        return $this->detalle($prueba);
    }

    /**
     * Las preguntas referencian su dimensión por una "clave" del payload
     * (las dimensiones aún no tienen id al crearse).
     */
    private function guardarContenido(Prueba $prueba, array $datos): void
    {
        $dimensionIds = [];
        foreach ($datos['dimensiones'] ?? [] as $dimension) {
            $creada = $prueba->dimensiones()->create(['nombre' => $dimension['nombre'], 'peso' => (int) $dimension['peso']]);
            $dimensionIds[(string) $dimension['clave']] = $creada->id;
        }

        foreach (array_values($datos['preguntas'] ?? []) as $orden => $pregunta) {
            $dimensionId = $dimensionIds[(string) $pregunta['dimension']] ?? null;
            if (! $dimensionId) {
                throw ValidationException::withMessages(["preguntas.{$orden}.dimension" => 'La pregunta debe pertenecer a una dimensión de la prueba.']);
            }

            $creada = $prueba->preguntas()->create([
                'dimension_id' => $dimensionId,
                'enunciado' => $pregunta['enunciado'],
                'orden' => $orden,
                'peso' => (int) ($pregunta['peso'] ?? 1),
            ]);

            foreach (array_values($pregunta['opciones']) as $ordenOpcion => $opcion) {
                $creada->opciones()->create(['texto' => $opcion['texto'], 'valor' => (float) $opcion['valor'], 'orden' => $ordenOpcion]);
            }
        }
    }
}
