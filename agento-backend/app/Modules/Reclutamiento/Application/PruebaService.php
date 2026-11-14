<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PruebaImagen;
use App\Modules\Reclutamiento\Models\PruebaPregunta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Banco de pruebas versionadas. Una prueba publicada es inmutable: editarla
 * crea una nueva versión en borrador. "validada" solo se marca con la
 * fuente/criterio que la respalda, registrada por quien la aprueba.
 *
 * Hay dos orígenes:
 * - Banco de Agento (empresa_id NULL): una sola prueba para los procesos de
 *   todas las empresas. Solo la administra un administrador de Agento.
 * - Pruebas propias de una empresa (como antes).
 */
class PruebaService
{
    private const CAMPOS_DIMENSION = [
        'codigo', 'nombre', 'peso', 'es_validez', 'nivel_medio_desde', 'nivel_alto_desde', 'alerta_desde',
        'interpretacion_alta', 'interpretacion_baja', 'pregunta_entrevista',
    ];

    public function __construct(private readonly RegistroActividad $actividad) {}

    public function listar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return Prueba::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $alcance->empresaId))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['area'] ?? null, fn ($q, $area) => $q->where('area_conocimiento', $area))
            ->when($filtros['busqueda'] ?? null, fn ($q, $texto) => $q->where('nombre', 'like', "%{$texto}%"))
            ->withCount([
                'preguntas',
                'dimensiones',
                // Usos en cualquier empresa: una prueba rendida no se elimina.
                'asignaciones' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->orderByRaw('empresa_id IS NOT NULL')
            ->orderBy('nombre')
            ->orderByDesc('version')
            ->paginate($perPage);
    }

    public function detalle(Prueba $prueba): Prueba
    {
        return $prueba->load(['dimensiones', 'escala', 'preguntas.imagen', 'preguntas.opciones.imagen', 'perfilesPuesto.dimensiones']);
    }

    public function crear(AlcanceSeleccion $alcance, array $datos): Prueba
    {
        $bancoAgento = (bool) ($datos['banco_agento'] ?? false);
        if ($bancoAgento && ! $alcance->esAdministrador()) {
            throw new AuthorizationException('Solo un administrador de Agento agrega pruebas al banco de Agento.');
        }

        $this->validarImagenes($alcance, $datos);

        return DB::transaction(function () use ($alcance, $datos, $bancoAgento) {
            $prueba = Prueba::create([
                'empresa_id' => $bancoAgento ? null : $alcance->empresaId,
                'familia' => (string) Str::uuid(),
                'version' => 1,
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'tipo' => $datos['tipo'],
                'area_conocimiento' => $this->area($datos),
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

    /**
     * Imagen para una pregunta u opción. Se guarda en disco privado; la
     * prueba la referencia por id al guardarse.
     */
    public function subirImagen(AlcanceSeleccion $alcance, UploadedFile $archivo): PruebaImagen
    {
        $disco = config('reclutamiento.cv.disco', 'local');
        $extension = match ($archivo->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $ruta = $archivo->storeAs('reclutamiento/pruebas/imagenes', Str::uuid().'.'.$extension, $disco);

        return PruebaImagen::create([
            'empresa_id' => $alcance->empresaId,
            'disco' => $disco,
            'ruta' => $ruta,
            'mime' => $archivo->getMimeType(),
            'tamano' => $archivo->getSize(),
            'subido_por' => $alcance->usuario->id,
        ]);
    }

    public function actualizar(AlcanceSeleccion $alcance, Prueba $prueba, array $datos): Prueba
    {
        $this->autorizarGestion($alcance, $prueba);
        if ($prueba->estado !== Prueba::BORRADOR) {
            throw new TransicionInvalidaException('Solo se editan pruebas en borrador. Crea una nueva versión para modificar una prueba publicada.');
        }

        $this->validarImagenes($alcance, $datos);

        return DB::transaction(function () use ($alcance, $prueba, $datos) {
            $prueba->update([
                ...collect($datos)->only(['nombre', 'descripcion', 'tipo', 'tiempo_limite_minutos'])->all(),
                'area_conocimiento' => $this->area($datos),
            ]);
            $prueba->perfilesPuesto()->delete();
            $prueba->escala()->delete();
            $prueba->preguntas()->delete();
            $prueba->dimensiones()->delete();
            $this->guardarContenido($prueba, $datos);
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_editada', $alcance->usuario->id);

            return $this->detalle($prueba->refresh());
        });
    }

    public function publicar(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        $this->autorizarGestion($alcance, $prueba);
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
        if ($prueba->dimensiones->where('peso', '>', 0)->isEmpty()) {
            throw ValidationException::withMessages(['dimensiones' => 'Al menos una dimensión debe tener peso mayor a 0.']);
        }

        DB::transaction(function () use ($alcance, $prueba) {
            // Se archiva la versión publicada anterior de la misma familia.
            $this->familia($prueba)->where('estado', Prueba::PUBLICADA)->update(['estado' => Prueba::ARCHIVADA]);
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
        $this->autorizarGestion($alcance, $prueba);
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
        $this->autorizarGestion($alcance, $prueba);

        // Si ya hay una versión en borrador de esta prueba, se sigue editando
        // esa en lugar de acumular borradores.
        $borrador = $this->familia($prueba)->where('estado', Prueba::BORRADOR)->orderByDesc('version')->first();
        if ($borrador) {
            return $this->detalle($borrador);
        }

        return DB::transaction(function () use ($alcance, $prueba) {
            $prueba->load(['dimensiones', 'escala', 'preguntas.opciones', 'perfilesPuesto.dimensiones']);
            $siguiente = (int) $this->familia($prueba)->max('version') + 1;

            $copia = Prueba::create([
                ...$prueba->only(['empresa_id', 'familia', 'nombre', 'descripcion', 'tipo', 'area_conocimiento', 'tiempo_limite_minutos']),
                'version' => $siguiente,
                'estado' => Prueba::BORRADOR,
                'validada' => false,
                'creado_por' => $alcance->usuario->id,
            ]);

            $this->guardarContenido($copia, [
                'dimensiones' => $prueba->dimensiones->map(fn ($d) => [
                    'clave' => (string) $d->id,
                    ...$d->only(self::CAMPOS_DIMENSION),
                ])->all(),
                'escala' => $prueba->escala->map(fn ($e) => ['valor' => $e->valor, 'etiqueta' => $e->etiqueta])->all(),
                'preguntas' => $prueba->preguntas->map(fn ($p) => [
                    'tipo' => $p->tipo,
                    'invertida' => $p->invertida,
                    'dimension' => (string) $p->dimension_id,
                    'enunciado' => $p->enunciado,
                    'imagen_id' => $p->imagen_id,
                    'peso' => $p->peso,
                    'opciones' => $p->opciones->map(fn ($o) => ['texto' => $o->texto, 'imagen_id' => $o->imagen_id, 'valor' => $o->valor])->all(),
                ])->all(),
                'perfiles_puesto' => $prueba->perfilesPuesto->map(fn ($perfil) => [
                    'nombre' => $perfil->nombre,
                    'dimensiones' => $perfil->dimensiones->pluck('codigo')->all(),
                ])->all(),
            ]);
            $this->actividad->registrar($alcance->empresaId, $copia, 'prueba_versionada', $alcance->usuario->id, null, ['desde_version' => $prueba->version]);

            return $this->detalle($copia);
        });
    }

    /**
     * Desactivar: deja de enviarse y de poder asignarse. Los resultados ya
     * registrados se conservan.
     */
    public function archivar(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        $this->autorizarGestion($alcance, $prueba);
        if ($prueba->estado === Prueba::ARCHIVADA) {
            throw new TransicionInvalidaException('La prueba ya está desactivada.');
        }
        $prueba->update(['estado' => Prueba::ARCHIVADA]);
        $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_archivada', $alcance->usuario->id);

        return $this->detalle($prueba);
    }

    /**
     * Activar una prueba desactivada:
     * - Si ya la rindió algún candidato, vuelve a publicarse tal cual (no se
     *   puede editar: cambiaría resultados registrados) y pasa a ser la
     *   versión vigente de su familia.
     * - Si nunca se usó, vuelve a borrador para revisarla y publicarla.
     */
    public function reactivar(AlcanceSeleccion $alcance, Prueba $prueba): Prueba
    {
        $this->autorizarGestion($alcance, $prueba);
        if ($prueba->estado !== Prueba::ARCHIVADA) {
            throw new TransicionInvalidaException('Solo se activa una prueba desactivada.');
        }

        DB::transaction(function () use ($alcance, $prueba) {
            if ($this->usos($prueba) > 0) {
                $this->familia($prueba)->where('estado', Prueba::PUBLICADA)->update(['estado' => Prueba::ARCHIVADA]);
                $prueba->update(['estado' => Prueba::PUBLICADA]);
            } else {
                // Una sola versión en borrador por familia.
                if ($this->familia($prueba)->where('estado', Prueba::BORRADOR)->exists()) {
                    throw new TransicionInvalidaException('Ya hay otra versión de esta prueba en borrador: edítala o elimínala primero.');
                }
                $prueba->update(['estado' => Prueba::BORRADOR]);
            }
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_reactivada', $alcance->usuario->id, null, ['estado' => $prueba->estado]);
        });

        return $this->detalle($prueba->refresh());
    }

    /**
     * Eliminar solo si ningún candidato la recibió: con asignaciones hay
     * resultados que conservar, así que se desactiva. Tampoco se elimina la
     * versión publicada que un proceso abierto envía al postular.
     */
    public function eliminar(AlcanceSeleccion $alcance, Prueba $prueba): void
    {
        $this->autorizarGestion($alcance, $prueba);
        if ($this->usos($prueba) > 0) {
            throw new TransicionInvalidaException('La prueba ya fue enviada a candidatos y tiene resultados registrados: desactívala en lugar de eliminarla.');
        }
        if ($prueba->estado === Prueba::PUBLICADA) {
            $enUso = ProcesoSeleccion::withoutGlobalScopes()
                ->where('prueba_familia', $prueba->familia)
                ->whereIn('estado', ['borrador', 'abierto', 'en_pausa'])
                ->pluck('codigo');
            if ($enUso->isNotEmpty()) {
                throw new TransicionInvalidaException('Los procesos '.$enUso->implode(', ').' envían esta prueba al postular. Quítala de esos procesos o publica otra versión antes de eliminarla.');
            }
        }

        DB::transaction(function () use ($alcance, $prueba) {
            $this->actividad->registrar($alcance->empresaId, $prueba, 'prueba_eliminada', $alcance->usuario->id, null, [
                'nombre' => $prueba->nombre, 'version' => $prueba->version,
            ]);
            // Dimensiones, preguntas, opciones, escala y tipos de puesto se
            // eliminan en cascada.
            $prueba->delete();
        });
    }

    /**
     * Profesiones o áreas ya usadas en las pruebas visibles: sugerencias al
     * crear una prueba de conocimientos y opciones del filtro del banco.
     *
     * @return array<int, string>
     */
    public function areas(AlcanceSeleccion $alcance): array
    {
        return Prueba::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $alcance->empresaId))
            ->whereNotNull('area_conocimiento')
            ->distinct()
            ->orderBy('area_conocimiento')
            ->pluck('area_conocimiento')
            ->all();
    }

    /**
     * Solo las pruebas de conocimientos tienen área.
     */
    private function area(array $datos): ?string
    {
        $area = trim((string) ($datos['area_conocimiento'] ?? ''));

        return ($datos['tipo'] ?? null) === 'conocimientos' && $area !== '' ? mb_substr($area, 0, 100) : null;
    }

    private function usos(Prueba $prueba): int
    {
        return PruebaAsignacion::withoutGlobalScopes()->where('prueba_id', $prueba->id)->count();
    }

    /**
     * Las pruebas del banco de Agento solo las modifica un administrador de
     * Agento; las de una empresa, solo esa empresa.
     *
     * @throws AuthorizationException
     */
    public function autorizarGestion(AlcanceSeleccion $alcance, Prueba $prueba): void
    {
        if ($prueba->esDeAgento() && ! $alcance->esAdministrador()) {
            throw new AuthorizationException('Las pruebas del banco de Agento solo las administra un administrador de Agento.');
        }
        if (! $prueba->esDeAgento() && $prueba->empresa_id !== $alcance->empresaId) {
            throw new AuthorizationException('La prueba no pertenece a la empresa activa.');
        }
    }

    /**
     * Solo imágenes subidas en la empresa activa (o del banco de Agento): no
     * se puede referenciar la imagen de otra empresa.
     */
    private function validarImagenes(AlcanceSeleccion $alcance, array $datos): void
    {
        $ids = collect($datos['preguntas'] ?? [])->flatMap(fn ($p) => [
            $p['imagen_id'] ?? null,
            ...collect($p['opciones'] ?? [])->pluck('imagen_id')->all(),
        ])->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $validas = PruebaImagen::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $alcance->empresaId))
            ->count();
        if ($validas !== $ids->count()) {
            throw ValidationException::withMessages(['preguntas' => 'Una de las imágenes no existe o no pertenece a la empresa activa.']);
        }
    }

    private function familia(Prueba $prueba)
    {
        return Prueba::withoutGlobalScopes()
            ->where('familia', $prueba->familia)
            ->when($prueba->esDeAgento(), fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $prueba->empresa_id));
    }

    /**
     * Las preguntas referencian su dimensión por una "clave" del payload
     * (las dimensiones aún no tienen id al crearse).
     */
    private function guardarContenido(Prueba $prueba, array $datos): void
    {
        $dimensionIds = [];
        $codigos = [];
        foreach ($datos['dimensiones'] ?? [] as $dimension) {
            $esValidez = (bool) ($dimension['es_validez'] ?? false);
            $codigo = isset($dimension['codigo']) && $dimension['codigo'] !== '' ? strtoupper((string) $dimension['codigo']) : null;
            $creada = $prueba->dimensiones()->create([
                'codigo' => $codigo,
                'nombre' => $dimension['nombre'],
                // La escala de validez no suma al puntaje de la prueba.
                'peso' => $esValidez ? 0 : (int) $dimension['peso'],
                'es_validez' => $esValidez,
                'nivel_medio_desde' => $dimension['nivel_medio_desde'] ?? null,
                'nivel_alto_desde' => $dimension['nivel_alto_desde'] ?? null,
                'alerta_desde' => $esValidez ? ($dimension['alerta_desde'] ?? null) : null,
                'interpretacion_alta' => $dimension['interpretacion_alta'] ?? null,
                'interpretacion_baja' => $dimension['interpretacion_baja'] ?? null,
                'pregunta_entrevista' => $dimension['pregunta_entrevista'] ?? null,
            ]);
            $dimensionIds[(string) $dimension['clave']] = $creada->id;
            if ($codigo !== null && ! $esValidez) {
                $codigos[] = $codigo;
            }
        }

        $escala = collect($datos['escala'] ?? [])->values();
        foreach ($escala as $orden => $punto) {
            $prueba->escala()->create(['valor' => (float) $punto['valor'], 'etiqueta' => $punto['etiqueta'], 'orden' => $orden]);
        }
        // Invertida = (mínimo + máximo) − valor: 1→5, 2→4… en una escala 1–5.
        $sumaExtremos = $escala->isEmpty() ? 0 : (float) $escala->min('valor') + (float) $escala->max('valor');

        foreach (array_values($datos['preguntas'] ?? []) as $orden => $pregunta) {
            $dimensionId = $dimensionIds[(string) $pregunta['dimension']] ?? null;
            if (! $dimensionId) {
                throw ValidationException::withMessages(["preguntas.{$orden}.dimension" => 'La pregunta debe pertenecer a una dimensión de la prueba.']);
            }
            $tipo = $pregunta['tipo'] ?? PruebaPregunta::OPCIONES;
            $invertida = $tipo === PruebaPregunta::ESCALA && (bool) ($pregunta['invertida'] ?? false);

            $creada = $prueba->preguntas()->create([
                'dimension_id' => $dimensionId,
                'tipo' => $tipo,
                'invertida' => $invertida,
                'enunciado' => $pregunta['enunciado'],
                'imagen_id' => $pregunta['imagen_id'] ?? null,
                'orden' => $orden,
                'peso' => (int) ($pregunta['peso'] ?? 1),
            ]);

            if ($tipo === PruebaPregunta::ESCALA) {
                if ($escala->isEmpty()) {
                    throw ValidationException::withMessages(['escala' => 'Define la escala de la prueba para las afirmaciones de escala.']);
                }
                foreach ($escala as $ordenOpcion => $punto) {
                    $creada->opciones()->create([
                        'texto' => $punto['etiqueta'],
                        'valor' => $invertida ? $sumaExtremos - (float) $punto['valor'] : (float) $punto['valor'],
                        'orden' => $ordenOpcion,
                    ]);
                }

                continue;
            }

            foreach (array_values($pregunta['opciones'] ?? []) as $ordenOpcion => $opcion) {
                $creada->opciones()->create([
                    'texto' => $opcion['texto'] ?? null,
                    'imagen_id' => $opcion['imagen_id'] ?? null,
                    'valor' => (float) $opcion['valor'],
                    'orden' => $ordenOpcion,
                ]);
            }
        }

        foreach (array_values($datos['perfiles_puesto'] ?? []) as $orden => $perfil) {
            $dimensiones = collect($perfil['dimensiones'] ?? [])->map(fn ($c) => strtoupper((string) $c))->unique()
                ->filter(fn ($c) => in_array($c, $codigos, true))->values();
            if ($dimensiones->isEmpty()) {
                continue;
            }
            $creado = $prueba->perfilesPuesto()->create(['nombre' => $perfil['nombre'], 'orden' => $orden]);
            $dimensiones->each(fn ($codigo) => $creado->dimensiones()->create(['codigo' => $codigo]));
        }
    }
}
