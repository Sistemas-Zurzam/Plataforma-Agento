<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Models\PruebaPregunta;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Lee un banco de preguntas y lo convierte al contenido del editor de
 * pruebas, SIN guardarlo: el encargado lo revisa (vista previa), corrige lo
 * que haga falta y recién entonces lo guarda como borrador.
 *
 * Orígenes:
 * - Excel (.xlsx) con la plantilla de Agento (hojas Prueba, Escala,
 *   Dimensiones, Preguntas y Tipos de puesto) o un .csv con solo preguntas.
 * - JSON (formato de escala: escala, dimensiones, puntuación, preguntas).
 * - Texto pegado (ej. copiado de un PDF o Word): una afirmación por línea.
 *
 * Lo que no se puede leer con seguridad (dimensión inexistente, fila vacía)
 * no detiene la importación: se informa como advertencia y queda para
 * completar en el editor. Las imágenes se agregan en el editor.
 */
class ImportadorPrueba
{
    public const ESCALA_LIKERT = [
        ['valor' => 1, 'etiqueta' => 'Totalmente en desacuerdo'],
        ['valor' => 2, 'etiqueta' => 'En desacuerdo'],
        ['valor' => 3, 'etiqueta' => 'Ni de acuerdo ni en desacuerdo'],
        ['valor' => 4, 'etiqueta' => 'De acuerdo'],
        ['valor' => 5, 'etiqueta' => 'Totalmente de acuerdo'],
    ];

    private const TIEMPO_POR_DEFECTO = 20;

    private const INSTRUCCIONES_POR_DEFECTO = 'Lee cada afirmación y marca qué tan de acuerdo estás. No hay respuestas buenas ni malas: responde con sinceridad, pensando en cómo eres habitualmente, y no dejes ninguna sin responder.';

    private const MAX_PREGUNTAS = 200;

    private const AVISO_TEXTO = 'Asigna la dimensión de cada afirmación y marca las invertidas según la guía de corrección.';

    /** @var array<int, string> */
    private array $advertencias = [];

    /**
     * @return array{contenido: array<string, mixed>, advertencias: array<int, string>}
     */
    public function desdeArchivo(string $ruta, string $extension): array
    {
        return match (strtolower($extension)) {
            'json' => $this->desdeJson($this->leerJson($ruta)),
            'xlsx', 'xls', 'csv' => $this->desdeHojaDeCalculo($ruta, strtolower($extension)),
            default => $this->error('archivo', 'Formato no soportado. Usa Excel (.xlsx), CSV o JSON. Para un PDF o Word, copia las afirmaciones y usa "Pegar afirmaciones".'),
        };
    }

    /**
     * Una afirmación por línea. Se quitan viñetas y numeración; "(R)" o
     * "(invertida)" al final la marca como invertida.
     *
     * @return array{contenido: array<string, mixed>, advertencias: array<int, string>}
     */
    public function desdeTexto(string $texto, ?string $nombre = null): array
    {
        $this->advertencias = [];
        $preguntas = collect(preg_split('/\R/u', $texto))
            ->map(fn (string $linea) => trim(preg_replace('/^\s*(?:[-•*·▪●]|\d+\s*[.)\-]?)\s+/u', '', $linea)))
            ->filter(fn (string $linea) => mb_strlen($linea) >= 3)
            ->map(function (string $linea) {
                $invertida = (bool) preg_match('/\((r|invertida)\)\s*$/iu', $linea);

                return $this->preguntaEscala(trim(preg_replace('/\((r|invertida)\)\s*$/iu', '', $linea)), null, $invertida);
            })
            ->values();

        if ($preguntas->isEmpty()) {
            $this->error('texto', 'No se encontraron afirmaciones. Pega una por línea.');
        }
        $this->advertencias[] = self::AVISO_TEXTO;

        return $this->resultado([
            'nombre' => $nombre ?: 'Prueba importada',
            'dimensiones' => [],
            'preguntas' => $preguntas->all(),
        ]);
    }

    /**
     * @return array{contenido: array<string, mixed>, advertencias: array<int, string>}
     */
    public function desdeJson(array $json): array
    {
        $this->advertencias = [];

        $escala = [];
        $min = $json['escala']['min'] ?? null;
        $max = $json['escala']['max'] ?? null;
        if (is_int($min) && is_int($max) && $min < $max && $max - $min <= 9) {
            for ($valor = $min; $valor <= $max; $valor++) {
                $escala[] = ['valor' => $valor, 'etiqueta' => trim((string) ($json['escala']['etiquetas'][(string) $valor] ?? (string) $valor))];
            }
        }

        $niveles = $json['puntuacion']['niveles'] ?? [];
        $validez = $json['puntuacion']['validez'] ?? [];
        $dimensiones = collect($json['dimensiones'] ?? [])->map(fn ($d) => $this->dimension(
            $d['codigo'] ?? null,
            $d['nombre'] ?? null,
            isset($validez['dimension']) && strtoupper((string) $validez['dimension']) === strtoupper((string) ($d['codigo'] ?? '')),
            $niveles['medio'][0] ?? null,
            $niveles['alto'][0] ?? null,
            $validez['alerta_desde'] ?? null,
            $d['interpretacion']['alto'] ?? null,
            $d['interpretacion']['bajo'] ?? null,
            $d['interpretacion']['pregunta_entrevista'] ?? null,
        ))->filter()->values()->all();

        $preguntas = collect($json['preguntas'] ?? [])
            ->sortBy(fn ($p) => (int) ($p['id'] ?? 0))
            ->map(fn ($p) => $this->preguntaEscala((string) ($p['texto'] ?? ''), $p['dimension'] ?? null, (bool) ($p['invertida'] ?? false)))
            ->values()->all();

        return $this->resultado([
            'nombre' => $json['nombre'] ?? null,
            'descripcion' => $json['instrucciones'] ?? null,
            'tiempo_limite_minutos' => $json['tiempo_limite_minutos'] ?? null,
            'tipo' => $json['tipo'] ?? null,
            'area_conocimiento' => $json['area_conocimiento'] ?? null,
            'escala' => $escala,
            'dimensiones' => $dimensiones,
            'preguntas' => $preguntas,
            'perfiles_puesto' => $json['perfiles_puesto'] ?? [],
        ]);
    }

    /**
     * Plantilla de Excel con las hojas que entiende el importador y un
     * ejemplo en cada una.
     */
    public function plantilla(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hojas = [
            'Prueba' => [
                ['Campo', 'Valor'],
                ['Nombre', 'Evaluación de Personalidad Laboral'],
                ['Tipo', 'psicometrica'],
                ['Profesión o área (solo conocimientos)', ''],
                ['Tiempo (minutos)', 20],
                ['Instrucciones', self::INSTRUCCIONES_POR_DEFECTO],
            ],
            'Escala' => [['Valor', 'Etiqueta'], ...array_map(fn ($p) => [$p['valor'], $p['etiqueta']], self::ESCALA_LIKERT)],
            'Dimensiones' => [
                ['Código', 'Nombre', 'Escala de validez (Sí/No)', 'Nivel medio desde', 'Nivel alto desde', 'Alerta desde', 'Nivel alto indica', 'Nivel bajo indica', 'Pregunta de entrevista'],
                ['RES', 'Responsabilidad y organización', 'No', 13, 20, '', 'Cumple plazos, ordenada(o).', 'Puede descuidar plazos.', '¿Cómo se organizó con varias tareas con fecha límite?'],
                ['DES', 'Deseabilidad social', 'Sí', '', '', 17, '', '', ''],
            ],
            'Preguntas' => [
                ['N°', 'Afirmación o pregunta', 'Código de dimensión', 'Invertida (Sí/No)', 'Opciones propias (texto=valor; texto=valor)'],
                [1, 'Cumplo mis tareas dentro del plazo establecido.', 'RES', 'No', ''],
                [2, 'Suelo dejar para el último momento lo que debo hacer.', 'RES', 'Sí', ''],
                [3, 'Nunca he cometido un error en mi trabajo.', 'DES', 'No', ''],
                [4, '¿Cuánto es 15 × 3?', 'RES', '', '35=0; 45=1; 55=0'],
            ],
            'Tipos de puesto' => [
                ['Tipo de puesto', 'Dimensiones (códigos separados por coma)'],
                ['Administrativo / contable', 'RES'],
            ],
        ];

        $primera = true;
        foreach ($hojas as $titulo => $filas) {
            $hoja = $primera ? $libro->getActiveSheet() : $libro->createSheet();
            $primera = false;
            $hoja->setTitle($titulo);
            $hoja->fromArray($filas);
            $hoja->getStyle('A1:'.$hoja->getHighestColumn().'1')->getFont()->setBold(true);
            foreach (range('A', $hoja->getHighestColumn()) as $columna) {
                $hoja->getColumnDimension($columna)->setAutoSize(true);
            }
        }
        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * @return array{contenido: array<string, mixed>, advertencias: array<int, string>}
     */
    private function desdeHojaDeCalculo(string $ruta, string $extension): array
    {
        $this->advertencias = [];
        try {
            $lector = IOFactory::createReader(match ($extension) {
                'csv' => 'Csv',
                'xls' => 'Xls',
                default => 'Xlsx',
            });
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
        } catch (Throwable) {
            $this->error('archivo', 'No se pudo leer el archivo. Verifica que sea un Excel o CSV válido.');
        }

        $hojas = [];
        foreach ($libro->getAllSheets() as $hoja) {
            $hojas[$this->normalizar($hoja->getTitle())] = $hoja->toArray(null, true, false, false);
        }
        $buscar = fn (string ...$nombres) => collect($nombres)->map(fn ($n) => $hojas[$n] ?? null)->first(fn ($h) => $h !== null);

        // Un CSV o un Excel de una sola hoja se lee como lista de preguntas.
        $filasPreguntas = $buscar('preguntas', 'afirmaciones') ?? (count($hojas) === 1 ? reset($hojas) : null);
        if (! $filasPreguntas) {
            $this->error('archivo', 'No se encontró la hoja "Preguntas". Descarga la plantilla para ver el formato.');
        }

        $datos = ['dimensiones' => [], 'preguntas' => [], 'perfiles_puesto' => []];

        foreach ($this->filas($buscar('prueba') ?? []) as $fila) {
            $campo = $this->normalizar((string) ($fila[0] ?? ''));
            $valor = trim((string) ($fila[1] ?? ''));
            match (true) {
                str_starts_with($campo, 'nombre') => $datos['nombre'] = $valor,
                str_starts_with($campo, 'tipo') => $datos['tipo'] = $valor,
                str_starts_with($campo, 'profesion'), str_starts_with($campo, 'area') => $datos['area_conocimiento'] = $valor,
                str_starts_with($campo, 'tiempo') => $datos['tiempo_limite_minutos'] = (int) $valor,
                str_starts_with($campo, 'instruc') => $datos['descripcion'] = $valor,
                default => null,
            };
        }

        $datos['escala'] = collect($this->filas($buscar('escala') ?? [], true))
            ->filter(fn ($f) => is_numeric($f[0] ?? null) && trim((string) ($f[1] ?? '')) !== '')
            ->map(fn ($f) => ['valor' => (float) $f[0], 'etiqueta' => trim((string) $f[1])])
            ->values()->all();

        foreach ($this->filas($buscar('dimensiones') ?? [], true) as $f) {
            $dimension = $this->dimension(
                $f[0] ?? null, $f[1] ?? null, $this->esSi($f[2] ?? null),
                $this->numero($f[3] ?? null), $this->numero($f[4] ?? null), $this->numero($f[5] ?? null),
                $f[6] ?? null, $f[7] ?? null, $f[8] ?? null,
            );
            if ($dimension) {
                $datos['dimensiones'][] = $dimension;
            }
        }

        [$cabecera, $columnas] = $this->columnasPreguntas($filasPreguntas);
        foreach ($this->filas($filasPreguntas, $cabecera) as $f) {
            $texto = trim((string) ($f[$columnas['texto']] ?? ''));
            if ($texto === '') {
                continue;
            }
            $dimension = $columnas['dimension'] !== null ? ($f[$columnas['dimension']] ?? null) : null;
            $opciones = $columnas['opciones'] !== null ? trim((string) ($f[$columnas['opciones']] ?? '')) : '';
            $datos['preguntas'][] = $opciones !== ''
                ? $this->preguntaOpciones($texto, $dimension, $opciones)
                : $this->preguntaEscala($texto, $dimension, $columnas['invertida'] !== null && $this->esSi($f[$columnas['invertida']] ?? null));
        }

        foreach ($this->filas($buscar('tipos de puesto', 'tipos puesto', 'perfiles') ?? [], true) as $f) {
            $datos['perfiles_puesto'][] = [
                'nombre' => trim((string) ($f[0] ?? '')),
                'dimensiones' => array_map('trim', explode(',', (string) ($f[1] ?? ''))),
            ];
        }

        return $this->resultado($datos);
    }

    /**
     * Completa valores por defecto, crea las dimensiones que solo aparecen en
     * las preguntas y valida referencias, acumulando advertencias.
     *
     * @return array{contenido: array<string, mixed>, advertencias: array<int, string>}
     */
    private function resultado(array $datos): array
    {
        $preguntas = array_slice($datos['preguntas'] ?? [], 0, self::MAX_PREGUNTAS);
        if (count($datos['preguntas'] ?? []) > self::MAX_PREGUNTAS) {
            $this->advertencias[] = 'Se importaron solo las primeras '.self::MAX_PREGUNTAS.' preguntas.';
        }
        if ($preguntas === []) {
            $this->error('archivo', 'El archivo no tiene preguntas.');
        }

        $dimensiones = collect($datos['dimensiones'] ?? [])->unique('codigo')->keyBy('codigo');
        $sinDimension = [];
        foreach ($preguntas as $i => $pregunta) {
            $codigo = $pregunta['dimension'];
            if ($codigo === null) {
                $sinDimension[] = $i + 1;
            } elseif (! $dimensiones->has($codigo)) {
                $dimensiones->put($codigo, $this->dimension($codigo, $codigo, false, null, null, null, null, null, null));
                $this->advertencias[] = "La dimensión {$codigo} no estaba definida: se creó con ese nombre; complétala.";
            }
        }
        if ($sinDimension !== [] && ! in_array(self::AVISO_TEXTO, $this->advertencias, true)) {
            $this->advertencias[] = 'Preguntas sin dimensión: '.implode(', ', array_slice($sinDimension, 0, 20)).(count($sinDimension) > 20 ? '…' : '').'. Asígnalas en el editor.';
        }
        if ($dimensiones->isEmpty()) {
            $dimensiones->put('GEN', $this->dimension('GEN', 'General', false, null, null, null, null, null, null));
        }

        $usaEscala = collect($preguntas)->contains('tipo', PruebaPregunta::ESCALA);
        $escala = $datos['escala'] ?? [];
        if ($usaEscala && $escala === []) {
            $escala = self::ESCALA_LIKERT;
            $this->advertencias[] = 'No se indicó la escala: se usó la escala de acuerdo de 1 a 5.';
        }

        $codigosRasgo = $dimensiones->where('es_validez', false)->keys()->all();
        $perfiles = collect($datos['perfiles_puesto'] ?? [])->map(fn ($p) => [
            'nombre' => mb_substr(trim((string) ($p['nombre'] ?? '')), 0, 120),
            'dimensiones' => collect($p['dimensiones'] ?? [])->map(fn ($c) => strtoupper(trim((string) $c)))
                ->filter(fn ($c) => in_array($c, $codigosRasgo, true))->unique()->values()->all(),
        ])->filter(fn ($p) => $p['nombre'] !== '' && $p['dimensiones'] !== [])->values()->all();

        $tipo = in_array($datos['tipo'] ?? null, ['psicometrica', 'conocimientos'], true)
            ? $datos['tipo']
            : ($usaEscala ? 'psicometrica' : 'conocimientos');

        return [
            'contenido' => [
                'nombre' => mb_substr(trim((string) ($datos['nombre'] ?? '')) ?: 'Prueba importada', 0, 150),
                'descripcion' => mb_substr(trim((string) ($datos['descripcion'] ?? '')) ?: self::INSTRUCCIONES_POR_DEFECTO, 0, 2000),
                'tipo' => $tipo,
                'area_conocimiento' => $tipo === 'conocimientos' ? (mb_substr(trim((string) ($datos['area_conocimiento'] ?? '')), 0, 100) ?: null) : null,
                'tiempo_limite_minutos' => max(1, min(240, (int) ($datos['tiempo_limite_minutos'] ?? 0) ?: self::TIEMPO_POR_DEFECTO)),
                'escala' => $usaEscala ? array_values($escala) : [],
                'dimensiones' => $dimensiones->values()->all(),
                'preguntas' => array_values($preguntas),
                'perfiles_puesto' => $perfiles,
            ],
            'advertencias' => array_values(array_unique($this->advertencias)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dimension(mixed $codigo, mixed $nombre, bool $esValidez, mixed $medio, mixed $alto, mixed $alerta, mixed $altaIndica, mixed $bajaIndica, mixed $pregunta): ?array
    {
        $codigo = $this->codigo($codigo);
        $nombre = trim((string) $nombre);
        if ($codigo === null && $nombre === '') {
            return null;
        }
        $codigo ??= $this->codigo(Str::upper(Str::substr(Str::ascii($nombre), 0, 3)));

        return [
            'clave' => $codigo,
            'codigo' => $codigo,
            'nombre' => mb_substr($nombre !== '' ? $nombre : $codigo, 0, 100),
            'peso' => $esValidez ? 0 : 1,
            'es_validez' => $esValidez,
            'nivel_medio_desde' => $esValidez ? null : $this->numero($medio),
            'nivel_alto_desde' => $esValidez ? null : $this->numero($alto),
            'alerta_desde' => $esValidez ? $this->numero($alerta) : null,
            'interpretacion_alta' => $this->textoOpcional($altaIndica),
            'interpretacion_baja' => $this->textoOpcional($bajaIndica),
            'pregunta_entrevista' => $this->textoOpcional($pregunta),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preguntaEscala(string $texto, mixed $dimension, bool $invertida): array
    {
        return [
            'tipo' => PruebaPregunta::ESCALA,
            'dimension' => $this->codigo($dimension),
            'enunciado' => mb_substr(trim($texto), 0, 2000),
            'invertida' => $invertida,
            'peso' => 1,
            'opciones' => [],
        ];
    }

    /**
     * "texto=valor; texto=valor". Sin "=valor" la opción vale 0.
     *
     * @return array<string, mixed>
     */
    private function preguntaOpciones(string $texto, mixed $dimension, string $opciones): array
    {
        $lista = collect(explode(';', $opciones))->map('trim')->filter()->map(function (string $opcion) {
            $partes = explode('=', $opcion);
            $valor = count($partes) > 1 ? array_pop($partes) : '0';

            return ['texto' => mb_substr(trim(implode('=', $partes)), 0, 500), 'valor' => is_numeric(trim($valor)) ? (float) trim($valor) : 0.0];
        })->values()->all();

        if (count($lista) < 2) {
            $this->advertencias[] = "La pregunta \"".Str::limit($texto, 40).'" necesita al menos dos opciones.';
        }

        return [
            'tipo' => PruebaPregunta::OPCIONES,
            'dimension' => $this->codigo($dimension),
            'enunciado' => mb_substr(trim($texto), 0, 2000),
            'invertida' => false,
            'peso' => 1,
            'opciones' => $lista,
        ];
    }

    /**
     * Ubica las columnas de la hoja de preguntas por su encabezado; sin
     * encabezado reconocible usa el orden de la plantilla.
     *
     * @return array{0: bool, 1: array{texto: int, dimension: ?int, invertida: ?int, opciones: ?int}}
     */
    private function columnasPreguntas(array $filas): array
    {
        $encabezado = array_map(fn ($c) => $this->normalizar((string) $c), $filas[0] ?? []);
        $buscar = fn (string ...$claves) => collect($encabezado)->search(fn ($c) => collect($claves)->contains(fn ($k) => str_contains($c, $k)));

        $texto = $buscar('afirmacion', 'pregunta', 'enunciado', 'texto', 'item');
        if ($texto === false) {
            // Sin encabezado: N°, texto, dimensión, invertida, opciones.
            $numerada = is_numeric($filas[0][0] ?? null);

            return [false, $numerada
                ? ['texto' => 1, 'dimension' => 2, 'invertida' => 3, 'opciones' => 4]
                : ['texto' => 0, 'dimension' => 1, 'invertida' => 2, 'opciones' => 3]];
        }

        $opcional = fn ($i) => $i === false ? null : $i;

        return [true, [
            'texto' => $texto,
            'dimension' => $opcional($buscar('dimension', 'codigo', 'factor', 'escala')),
            'invertida' => $opcional($buscar('invertid', 'invers', '(r)')),
            'opciones' => $opcional($buscar('opcion', 'alternativa', 'respuesta')),
        ]];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function filas(array $filas, bool $saltarEncabezado = true): array
    {
        return array_values(array_filter(
            $saltarEncabezado ? array_slice($filas, 1) : $filas,
            fn ($fila) => collect($fila)->contains(fn ($c) => trim((string) $c) !== ''),
        ));
    }

    private function codigo(mixed $valor): ?string
    {
        $codigo = Str::upper(trim((string) $valor));

        return $codigo === '' ? null : mb_substr(preg_replace('/\s+/', '', $codigo), 0, 10);
    }

    private function numero(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, 1000);
    }

    private function esSi(mixed $valor): bool
    {
        return in_array($this->normalizar((string) $valor), ['si', 's', 'x', '1', 'true', 'r', 'invertida'], true);
    }

    private function normalizar(string $texto): string
    {
        return trim(Str::lower(Str::ascii($texto)));
    }

    private function leerJson(string $ruta): array
    {
        $json = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($json)) {
            $this->error('archivo', 'El archivo no es un JSON válido.');
        }

        return $json;
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
