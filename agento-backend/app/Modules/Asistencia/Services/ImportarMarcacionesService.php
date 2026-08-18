<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Infrastructure\TransactionXlsxReader;
use App\Modules\Asistencia\Models\AsistenciaImportacion;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaIdentidad;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class ImportarMarcacionesService
{
    public function __construct(
        private readonly TransactionXlsxReader $lector,
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    public function previsualizar(Empresa $empresa, UploadedFile $archivo): array
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        if ($filas === []) throw \Illuminate\Validation\ValidationException::withMessages(['archivo' => ['El archivo no contiene marcaciones válidas.']]);
        $documentos = collect($filas)->pluck('person_id')->unique()->values();
        $porDocumento = Colaborador::query()->where('empresa_id', $empresa->id)
            ->whereIn('numero_documento', $documentos)->get()->keyBy('numero_documento');
        $porIdentidad = AsistenciaIdentidad::query()->where('empresa_id', $empresa->id)
            ->whereIn('person_id', $documentos)->with('colaborador')->get()
            ->filter(fn ($identidad) => $identidad->colaborador)->mapWithKeys(fn ($identidad) => [$identidad->person_id => $identidad->colaborador]);
        $reconocidos = $porDocumento->union($porIdentidad);
        $fechas = collect($filas)->pluck('marcado_at');
        $desde = $fechas->min()?->toDateString();
        $hasta = $fechas->max()?->toDateString();
        $reconocidosIds = $reconocidos->pluck('id')->unique();
        $existentes = AsistenciaMarcacion::query()->where('empresa_id', $empresa->id)
            ->whereIn('person_id', $documentos)->whereBetween('marcado_at', [$desde.' 00:00:00', $hasta.' 23:59:59'])
            ->get(['person_id', 'marcado_at', 'origen'])->mapWithKeys(fn ($marca) => [
                $marca->person_id.'|'.$marca->marcado_at->format('Y-m-d H:i:s').'|'.$marca->origen => true,
            ]);
        $duplicadas = collect($filas)->filter(function ($fila) use ($existentes) {
            $origen = Str::limit(Str::slug((string) $fila['origen'], '_') ?: 'transaction', 50, '');
            return $existentes->has($fila['person_id'].'|'.$fila['marcado_at']->format('Y-m-d H:i:s').'|'.$origen);
        })->count();
        $preparacion = ['listos' => 0, 'falta_horario' => 0, 'falta_calendario' => 0, 'faltan_ambos' => 0];

        Colaborador::query()->whereIn('id', $reconocidosIds)->with([
            'calendario' => fn ($query) => $query->whereBetween('fecha', [$desde, $hasta]),
        ])->get()->each(function ($colaborador) use (&$preparacion) {
            $horario = $colaborador->horario_id !== null;
            $calendario = $colaborador->calendario->isNotEmpty();
            $clave = match (true) {
                $horario && $calendario => 'listos',
                ! $horario && ! $calendario => 'faltan_ambos',
                ! $horario => 'falta_horario',
                default => 'falta_calendario',
            };
            $preparacion[$clave]++;
        });

        return [
            'archivo_nombre' => $archivo->getClientOriginalName(), 'archivo_tamano' => $archivo->getSize(),
            'fecha_minima' => $desde, 'fecha_maxima' => $hasta, 'registros_validos' => count($filas),
            'registros_invalidos' => $this->lector->filasInvalidas(), 'colaboradores_reconocidos' => $reconocidosIds->count(),
            'registros_duplicados' => $duplicadas, 'registros_nuevos_estimados' => count($filas) - $duplicadas,
            'person_ids_sin_coincidencia' => $documentos->diff($reconocidos->keys())->count(),
            'marcaciones_sin_coincidencia' => collect($filas)->whereNotIn('person_id', $reconocidos->keys())->count(),
            'preparacion' => $preparacion,
        ];
    }

    public function importar(Empresa $empresa, UploadedFile $archivo, ?int $usuarioId): AsistenciaImportacion
    {
        $filas = $this->lector->leer($archivo->getRealPath());
        if ($filas === []) throw \Illuminate\Validation\ValidationException::withMessages(['archivo' => ['El archivo no contiene marcaciones válidas.']]);
        $documentos = collect($filas)->pluck('person_id')->unique()->values();
        $porDocumento = Colaborador::query()->where('empresa_id', $empresa->id)
            ->whereIn('numero_documento', $documentos)->get()->keyBy('numero_documento');
        $porIdentidad = AsistenciaIdentidad::query()->where('empresa_id', $empresa->id)
            ->whereIn('person_id', $documentos)->with('colaborador')->get()
            ->filter(fn ($identidad) => $identidad->colaborador)->mapWithKeys(fn ($identidad) => [$identidad->person_id => $identidad->colaborador]);
        $colaboradores = $porDocumento->union($porIdentidad);

        $importacion = DB::transaction(function () use ($empresa, $archivo, $usuarioId, $filas, $colaboradores) {
            $importacion = AsistenciaImportacion::query()->create([
                'empresa_id' => $empresa->id, 'importado_por' => $usuarioId,
                'archivo_nombre' => $archivo->getClientOriginalName(), 'archivo_hash' => hash_file('sha256', $archivo->getRealPath()),
                'estado' => 'procesando', 'filas_totales' => count($filas),
            ]);
            $nuevas = $duplicadas = $observadas = 0;
            $fechas = [];

            foreach ($filas as $fila) {
                $colaborador = $colaboradores->get($fila['person_id']);
                if (! $colaborador) $observadas++;
                $origen = Str::limit(Str::slug((string) $fila['origen'], '_') ?: 'transaction', 50, '');
                $marcacion = AsistenciaMarcacion::query()->firstOrCreate([
                    'empresa_id' => $empresa->id, 'person_id' => $fila['person_id'],
                    'marcado_at' => $fila['marcado_at'], 'origen' => $origen,
                ], [
                    'colaborador_id' => $colaborador?->id, 'dispositivo' => $fila['dispositivo'],
                    'datos_origen' => collect($fila)->except('marcado_at')->all(),
                ]);
                if ($colaborador && $marcacion->colaborador_id !== $colaborador->id) {
                    $marcacion->update(['colaborador_id' => $colaborador->id]);
                }
                $marcacion->wasRecentlyCreated ? $nuevas++ : $duplicadas++;
                DB::table('asistencia_importacion_marcaciones')->insertOrIgnore([
                    'importacion_id' => $importacion->id, 'marcacion_id' => $marcacion->id,
                    'fue_nueva' => $marcacion->wasRecentlyCreated, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $fechas[] = $fila['marcado_at']->toDateString();
            }

            $importacion->update([
                'estado' => 'completada', 'fecha_minima' => $fechas ? min($fechas) : null,
                'fecha_maxima' => $fechas ? max($fechas) : null, 'filas_nuevas' => $nuevas,
                'filas_duplicadas' => $duplicadas, 'filas_observadas' => $observadas,
            ]);
            return $importacion;
        });

        // Se procesa todo el rango importado (no solo los días con marcación) para
        // cada colaborador que aparece en este archivo: así los días sin marcación
        // esperada (home office, descanso, feriado) quedan resueltos correctamente
        // en vez de quedarse en "Sin procesar". Solo se toca a los colaboradores
        // presentes en este archivo — uno que todavía no tiene ninguna marcación
        // importada (p. ej. llega en un archivo aparte) no se reprocesa acá, para
        // no marcarle "falta" antes de que su propio archivo se importe.
        $colaboradoresAfectados = $colaboradores->unique(fn ($colaborador) => $colaborador->id);
        if ($importacion->fecha_minima && $importacion->fecha_maxima) {
            $fechaDesde = Carbon::parse($importacion->fecha_minima);
            $fechaHasta = Carbon::parse($importacion->fecha_maxima);

            foreach ($colaboradoresAfectados as $colaborador) {
                for ($fecha = $fechaDesde->copy(); $fecha->lte($fechaHasta); $fecha->addDay()) {
                    if ($fecha->lt($colaborador->fecha_ingreso->startOfDay())) {
                        continue;
                    }
                    $protegido = AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)
                        ->whereIn('estado', ['cerrado', 'enviado_nomina'])
                        ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
                        ->whereDate('fecha_fin', '>=', $fecha->toDateString())->exists();
                    if ($protegido) continue;
                    $this->procesador->procesar($colaborador, $fecha->copy());
                }
            }
        }

        $this->auditoria->registrar(
            $empresa->id, $usuarioId, 'marcaciones_importadas', $importacion, $archivo->getClientOriginalName(), null,
            ['filas_totales' => count($filas), 'filas_nuevas' => $importacion->filas_nuevas, 'filas_duplicadas' => $importacion->filas_duplicadas, 'filas_observadas' => $importacion->filas_observadas]
        );

        return $importacion->fresh('marcaciones');
    }

    public function noAsociadas(Empresa $empresa): array
    {
        return AsistenciaMarcacion::query()->where('empresa_id', $empresa->id)->whereNull('colaborador_id')
            ->selectRaw("person_id, MAX(JSON_UNQUOTE(JSON_EXTRACT(datos_origen, '$.nombre'))) as nombre, COUNT(*) as marcaciones, MIN(marcado_at) as desde, MAX(marcado_at) as hasta")
            ->groupBy('person_id')->orderByDesc('marcaciones')->get()->toArray();
    }

    public function asociar(Empresa $empresa, Colaborador $colaborador, string $personId): int
    {
        abort_unless((int) $colaborador->empresa_id === (int) $empresa->id, 404);
        return DB::transaction(function () use ($empresa, $colaborador, $personId) {
            AsistenciaIdentidad::query()->updateOrCreate(
                ['empresa_id' => $empresa->id, 'person_id' => $personId],
                ['colaborador_id' => $colaborador->id]
            );
            $marcaciones = AsistenciaMarcacion::query()->where('empresa_id', $empresa->id)
                ->where('person_id', $personId)->whereNull('colaborador_id')->get();
            AsistenciaMarcacion::query()->whereKey($marcaciones->modelKeys())->update(['colaborador_id' => $colaborador->id]);
            $marcaciones->pluck('marcado_at')->map(fn ($fecha) => $fecha->toDateString())->unique()
                ->each(fn ($fecha) => $this->procesador->procesar($colaborador, Carbon::parse($fecha)->startOfDay()));
            return $marcaciones->count();
        });
    }

}
