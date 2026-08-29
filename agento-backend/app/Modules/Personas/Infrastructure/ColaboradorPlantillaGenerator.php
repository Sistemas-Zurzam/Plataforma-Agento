<?php

namespace App\Modules\Personas\Infrastructure;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Genera la plantilla .xlsx de importación de colaboradores: una fila por
 * colaborador. numero_documento y las fechas quedan forzadas a formato
 * texto ('@') para que Excel nunca las reinterprete como número/fecha
 * serial — mismo cuidado que en HorarioPlantillaGenerator, por el bug real
 * de DNI con cero a la izquierda que ya corregimos en marcaciones.
 */
class ColaboradorPlantillaGenerator
{
    private const ENCABEZADOS = [
        'sede', 'area', 'horario', 'nombres',
        // Apellido paterno/materno por separado (antes un solo "apellidos")
        // — exigido por las estructuras E4/E7 de PLAME (SUNAT). Se insertan
        // acá, no al final, porque conceptualmente son parte de la
        // identidad de la persona (junto a nombres/documento) — el resto de
        // columnas ya calcula su letra dinámicamente (ver agregarValidaciones()/
        // CAMPOS_TEXTO), así que insertar acá no desalinea nada.
        'apellido_paterno', 'apellido_materno',
        'tipo_documento', 'numero_documento',
        'fecha_nacimiento', 'celular_colaborador', 'celular_referencia', 'email', 'direccion',
        'cargo', 'tipo_contrato', 'tipo_trabajador', 'regimen_laboral', 'modalidad_trabajo',
        'fecha_ingreso', 'fecha_fin_contrato', 'salario', 'moneda_salario', 'periodicidad_pago',
        'asignacion_familiar', 'sistema_previsional',
        // Agregados después de la v1 (Sección: faltaban campos de residencia,
        // banderas de cálculo y datos bancarios) — van al final para no
        // correr las columnas ya existentes en plantillas que alguien ya
        // esté usando.
        'pais_residencia', 'ciudad_residencia', 'distrito_residencia',
        'contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra',
        'cts_cuenta', 'banco', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci',
        // Paridad exacta con StoreColaboradorRequest / NuevoColaboradorModal.
        'tolerancia_particular_minutos', 'dias_descanso_rotativo_por_semana',
        'es_trabajador_confianza',
        // Va al final por el mismo motivo que los anteriores (no correr
        // columnas de plantillas ya en uso). Se resuelve por nombre exacto,
        // igual que sede/area/horario, contra las empresas que el usuario
        // realmente administra — ver ImportarColaboradoresService.
        'empresa',
    ];

    /**
     * Campos que deben quedar en formato texto para evitar interpretación
     * numérica/fecha de Excel — se resuelven por NOMBRE (ver columna()),
     * nunca por letra hardcodeada: insertar una columna (como acá,
     * apellido_paterno/materno) corre todas las letras siguientes, y una
     * letra fija se habría desalineado en silencio.
     */
    private const CAMPOS_TEXTO = ['numero_documento', 'fecha_nacimiento', 'fecha_ingreso', 'fecha_fin_contrato', 'numero_cuenta', 'cci'];

    /**
     * @param  array<int, string>  $nombresHorarios  Nombres de los horarios
     *      activos registrados en el sistema, para convertir la columna
     *      "horario" en una lista desplegable real en vez de texto libre.
     *      Si viene vacío (aún no hay horarios registrados), la columna
     *      queda como texto libre igual que antes.
     * @param  array<int, string>  $nombresEmpresas  Nombres de las empresas
     *      que el usuario que descarga la plantilla realmente administra,
     *      para convertir la columna "empresa" en una lista desplegable —
     *      mismo criterio de autorización que ImportarColaboradoresService,
     *      así nunca se puede escribir a mano una empresa ajena.
     */
    public function generar(array $nombresHorarios = [], array $nombresEmpresas = []): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Colaboradores');

        foreach (self::ENCABEZADOS as $indice => $titulo) {
            $columna = $this->columna($indice);
            $hoja->setCellValue("{$columna}1", $titulo);
        }
        $ultimaColumna = $this->columna(count(self::ENCABEZADOS) - 1);
        $hoja->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true);

        foreach (self::CAMPOS_TEXTO as $campo) {
            $columna = $this->columna($this->indiceDe($campo));
            $hoja->getStyle("{$columna}2:{$columna}200")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $this->llenarEjemplo($hoja, $nombresHorarios[0] ?? null, $nombresEmpresas[0] ?? null);
        $this->agregarValidaciones($hoja);
        $this->agregarListasDinamicas($libro, $hoja, $nombresHorarios, $nombresEmpresas);

        foreach (array_keys(self::ENCABEZADOS) as $indice) {
            $hoja->getColumnDimension($this->columna($indice))->setAutoSize(true);
        }

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * Con más de 26 columnas hace falta letra doble (AA, AB...) — chr() solo
     * alcanza para A-Z, por eso la conversión real es base-26 posicional.
     */
    private function columna(int $indice): string
    {
        $posicion = $indice + 1;
        $letras = '';
        while ($posicion > 0) {
            $resto = ($posicion - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $posicion = intdiv($posicion - 1, 26);
        }

        return $letras;
    }

    private function llenarEjemplo(Worksheet $hoja, ?string $nombreHorarioReal, ?string $nombreEmpresaReal): void
    {
        $ejemplo = [
            'sede' => 'Sede Principal',
            'area' => 'Sistemas',
            'horario' => $nombreHorarioReal ?? '8:00 - 18:00 | 9h',
            'nombres' => 'JUAN CARLOS',
            'apellido_paterno' => 'PEREZ',
            'apellido_materno' => 'RAMIREZ',
            'tipo_documento' => 'dni',
            'numero_documento' => '09876543',
            'fecha_nacimiento' => '1995-05-20',
            'celular_colaborador' => '987654321',
            'celular_referencia' => '912345678',
            'email' => 'juan.perez@ejemplo.com',
            'direccion' => 'Av. Ejemplo 123',
            'cargo' => 'Analista',
            'tipo_contrato' => 'indefinido',
            'tipo_trabajador' => 'trabajador',
            'regimen_laboral' => 'General',
            'modalidad_trabajo' => 'presencial',
            'fecha_ingreso' => '2026-01-01',
            'fecha_fin_contrato' => '',
            'salario' => '2500',
            'moneda_salario' => 'PEN',
            'periodicidad_pago' => 'mensual',
            'asignacion_familiar' => '',
            'sistema_previsional' => 'onp',
            'pais_residencia' => 'Perú',
            'ciudad_residencia' => 'Lima',
            'distrito_residencia' => 'San Isidro',
            'contabilizar_tardanzas' => 'Sí',
            'contabilizar_faltas' => 'Sí',
            'contabilizar_horas_extra' => 'Sí',
            'cts_cuenta' => '',
            'banco' => 'BCP',
            'numero_cuenta' => '19412345678012',
            'tipo_cuenta' => 'ahorro',
            'moneda_cuenta' => 'PEN',
            'cci' => '00219400123456780154',
            'tolerancia_particular_minutos' => '',
            'dias_descanso_rotativo_por_semana' => '',
            'es_trabajador_confianza' => 'No',
            'empresa' => $nombreEmpresaReal ?? 'Empresa Ejemplo S.A.C.',
        ];

        foreach (self::ENCABEZADOS as $indice => $campo) {
            $columna = $this->columna($indice);
            $hoja->setCellValueExplicit("{$columna}2", $ejemplo[$campo], DataType::TYPE_STRING);
        }
    }

    /**
     * Antes usaba rangos de letra hardcodeados (ej. 'AJ2:AJ200') — con 39
     * columnas y varias inserciones ya encima (residencia, bancarios,
     * ahora apellido_paterno/materno), una letra fija por campo es
     * exactamente el tipo de dato que se desalinea en silencio cada vez
     * que se agrega una columna. Se resuelve por nombre de campo, no por
     * letra, para que esto deje de ser un riesgo hacia adelante.
     */
    private function agregarValidaciones(Worksheet $hoja): void
    {
        $listas = [
            'tipo_documento' => 'dni,ce,pasaporte',
            'tipo_contrato' => 'plazo_fijo,indefinido,locacion_servicios,practicas',
            'tipo_trabajador' => 'trabajador,practicante,locador',
            'regimen_laboral' => 'General,Micro Empresa,Pequeña Empresa,Locacion de Servicios',
            'modalidad_trabajo' => 'presencial,remoto,hibrido',
            'moneda_salario' => 'PEN,USD',
            'periodicidad_pago' => 'mensual,quincenal,semanal',
            'contabilizar_tardanzas' => 'Sí,No',
            'contabilizar_faltas' => 'Sí,No',
            'contabilizar_horas_extra' => 'Sí,No',
            'tipo_cuenta' => 'ahorro,corriente',
            'moneda_cuenta' => 'PEN,USD',
            'es_trabajador_confianza' => 'Sí,No',
        ];

        foreach ($listas as $campo => $opciones) {
            $columna = $this->columna($this->indiceDe($campo));
            $this->listaDesplegable($hoja, "{$columna}2:{$columna}200", $opciones);
        }
    }

    private function indiceDe(string $campo): int
    {
        $indice = array_search($campo, self::ENCABEZADOS, true);
        if ($indice === false) {
            throw new \LogicException("Campo \"{$campo}\" no está en ENCABEZADOS.");
        }

        return $indice;
    }

    /**
     * PhpSpreadsheet no tiene un "aplicar a rango" directo para validación de
     * tipo lista — se clona la misma regla celda por celda del rango.
     */
    private function listaDesplegable(Worksheet $hoja, string $rango, string $opciones): void
    {
        $validacion = new DataValidation;
        $validacion->setType(DataValidation::TYPE_LIST);
        $validacion->setErrorStyle(DataValidation::STYLE_STOP);
        $validacion->setAllowBlank(true);
        $validacion->setShowDropDown(true);
        $validacion->setFormula1('"'.$opciones.'"');

        [$inicio, $fin] = explode(':', $rango);
        $columna = preg_replace('/\d+/', '', $inicio);
        $filaInicio = (int) preg_replace('/\D+/', '', $inicio);
        $filaFin = (int) preg_replace('/\D+/', '', $fin);
        for ($fila = $filaInicio; $fila <= $filaFin; $fila++) {
            $hoja->getCell("{$columna}{$fila}")->setDataValidation(clone $validacion);
        }
    }

    /**
     * Ninguna de las dos listas cabe como lista literal (Excel limita la
     * fórmula de un DataValidation tipo lista a 255 caracteres, y un grupo
     * con varios horarios o empresas la supera fácil) — por eso los nombres
     * reales se escriben en una hoja auxiliar oculta (horarios en la
     * columna A, empresas en la B) y "horario"/"empresa" apuntan a ese
     * rango en vez de a un texto fijo.
     *
     * @param  array<int, string>  $nombresHorarios
     * @param  array<int, string>  $nombresEmpresas
     */
    private function agregarListasDinamicas(Spreadsheet $libro, Worksheet $hojaPrincipal, array $nombresHorarios, array $nombresEmpresas): void
    {
        if ($nombresHorarios === [] && $nombresEmpresas === []) {
            return;
        }

        $hojaListas = $libro->createSheet();
        $hojaListas->setTitle('Listas');
        $hojaListas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        if ($nombresHorarios !== []) {
            foreach ($nombresHorarios as $indice => $nombre) {
                $hojaListas->setCellValueExplicit('A'.($indice + 1), $nombre, DataType::TYPE_STRING);
            }
            $columnaHorario = $this->columna($this->indiceDe('horario'));
            $this->aplicarListaDesdeRango($hojaPrincipal, $columnaHorario, 'Listas!$A$1:$A$'.count($nombresHorarios));
        }

        if ($nombresEmpresas !== []) {
            foreach ($nombresEmpresas as $indice => $nombre) {
                $hojaListas->setCellValueExplicit('B'.($indice + 1), $nombre, DataType::TYPE_STRING);
            }
            $columnaEmpresa = $this->columna(count(self::ENCABEZADOS) - 1);
            $this->aplicarListaDesdeRango($hojaPrincipal, $columnaEmpresa, 'Listas!$B$1:$B$'.count($nombresEmpresas));
        }
    }

    private function aplicarListaDesdeRango(Worksheet $hoja, string $columna, string $rango): void
    {
        $validacion = new DataValidation;
        $validacion->setType(DataValidation::TYPE_LIST);
        $validacion->setErrorStyle(DataValidation::STYLE_STOP);
        $validacion->setAllowBlank(true);
        $validacion->setShowDropDown(true);
        $validacion->setFormula1($rango);

        for ($fila = 2; $fila <= 200; $fila++) {
            $hoja->getCell("{$columna}{$fila}")->setDataValidation(clone $validacion);
        }
    }
}
