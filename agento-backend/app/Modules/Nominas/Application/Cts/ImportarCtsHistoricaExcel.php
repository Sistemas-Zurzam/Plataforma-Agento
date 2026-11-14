<?php

namespace App\Modules\Nominas\Application\Cts;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\Cts\CtsExcelHistoricoReader;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Services\BeneficioSocialService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Carga una CTS ya calculada por RR.HH. en Excel como la versión vigente de
 * esa CTS (origen 'excel'). Sirve para el primer semestre en Agento, cuando no
 * hay planillas de los meses anteriores: queda como histórico de lo que la
 * empresa depositó y permite generar el TXT, registrar depósitos y emitir la
 * boleta de CTS. Los importes se toman del Excel tal cual; no se recalculan.
 */
class ImportarCtsHistoricaExcel
{
    /** Tolerancia de redondeo para validar que las columnas del Excel cuadren entre sí. */
    private const TOLERANCIA = 0.05;

    public function __construct(private readonly CtsExcelHistoricoReader $lector) {}

    /** @return array<string, mixed> */
    public function previsualizar(Empresa $empresa, string $tipo, int $anio, string $ruta): array
    {
        try {
            $leido = $this->lector->leer($ruta);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        $colaboradores = Colaborador::withTrashed()->where('empresa_id', $empresa->id)->get(['id', 'empresa_id', 'nombres', 'apellidos', 'legajo', 'numero_documento', 'cts_cuenta', 'deleted_at']);
        $porDocumento = [];
        foreach ($colaboradores as $c) {
            $porDocumento[self::clave((string) $c->numero_documento)][] = $c;
        }

        $vistos = [];
        $filas = [];
        foreach ($leido['filas'] as $fila) {
            $v = $fila['valores'];
            $errores = [];
            $advertencias = [];
            $candidatos = $porDocumento[self::clave($fila['documento'])] ?? [];
            $colaborador = count($candidatos) === 1 ? $candidatos[0] : null;
            if (! $candidatos) {
                $errores[] = 'No hay un colaborador de la empresa con este documento.';
            } elseif (count($candidatos) > 1) {
                $errores[] = 'Hay más de un colaborador con este documento.';
            }
            if (isset($vistos[self::clave($fila['documento'])])) {
                $errores[] = 'Documento repetido en el Excel (fila '.$vistos[self::clave($fila['documento'])].').';
            }
            $vistos[self::clave($fila['documento'])] ??= $fila['fila'];
            foreach ($fila['invalidos'] as $campo) {
                $errores[] = "El valor de \"{$campo}\" no es un número.";
            }

            $sueldo = round((float) $v['sueldo'], 2);
            $dias = (int) round((float) $v['dias']);
            $grati = round((float) $v['gratificacion'], 2);
            $sexto = round($v['sexto'] ?? $grati / 6, 2);
            $he = round((float) $v['horas_extra'], 2);
            $comisiones = round((float) $v['comisiones'], 2);
            $base = round($v['base'] ?? ($sueldo + $sexto + $he + $comisiones), 2);
            $cts100 = round($v['cts_100'] ?? ($base * $dias / 360), 2);
            $monto = round((float) $v['depositar'], 2);

            if ($v['depositar'] === null || $monto < 0) {
                $errores[] = 'Falta el importe de CTS a depositar.';
            }
            if ($dias < 1 || $dias > 180) {
                $errores[] = 'Los días computables deben estar entre 1 y 180.';
            }
            if ($sueldo <= 0) {
                $errores[] = 'Falta el sueldo base.';
            }
            if (abs($base - ($sueldo + $sexto + $he + $comisiones)) > self::TOLERANCIA) {
                $advertencias[] = 'La base computable no es la suma de sueldo, 1/6 de gratificación y promedios.';
            }
            if (abs($cts100 - $base * $dias / 360) > self::TOLERANCIA) {
                $advertencias[] = 'La CTS al 100% no es base / 360 x días.';
            }
            $tasa = $cts100 > 0 ? round($monto / $cts100, 4) : 1.0;
            foreach ([0.5, 1.0] as $esperada) {
                if (abs($monto - round($cts100 * $esperada, 2)) <= self::TOLERANCIA) {
                    $tasa = $esperada;
                }
            }
            if (! in_array($tasa, [0.5, 1.0], true)) {
                $advertencias[] = 'El importe a depositar no es el 50% ni el 100% de la CTS.';
            }
            // Cuenta CTS: el Excel completa la ficha solo si está vacía; nunca reemplaza la registrada.
            $cuentaExcel = $fila['cuenta']['numero'] ?? null;
            $cuentaFicha = $colaborador ? preg_replace('/\D/', '', (string) $colaborador->cts_cuenta) : '';
            $registrarCuenta = null;
            if ($fila['cuenta']['invalida'] ?? false) {
                $advertencias[] = 'La cuenta CTS del Excel no es válida (debe ser texto de 10 a 20 dígitos); no se usa.';
            }
            if ($colaborador && $cuentaFicha === '') {
                if ($cuentaExcel) {
                    $registrarCuenta = $cuentaExcel;
                    $advertencias[] = 'Se registrará en la ficha la cuenta CTS del Excel.';
                } else {
                    $advertencias[] = 'La ficha no tiene cuenta CTS: no entrará al TXT hasta completarla.';
                }
            } elseif ($colaborador && $cuentaExcel && $cuentaExcel !== $cuentaFicha) {
                $advertencias[] = 'La cuenta CTS del Excel es distinta a la de la ficha: se mantiene la de la ficha.';
            }

            $filas[] = [
                'fila' => $fila['fila'],
                'documento' => $fila['documento'],
                'colaborador_id' => $colaborador?->id,
                'colaborador' => $colaborador ? trim($colaborador->nombres.' '.$colaborador->apellidos) : null,
                'legajo' => $colaborador?->legajo,
                'sueldo' => $sueldo, 'dias' => $dias, 'gratificacion' => $grati, 'sexto' => $sexto,
                'horas_extra' => $he, 'comisiones' => $comisiones, 'base' => $base, 'cts_100' => $cts100,
                'tasa' => $tasa, 'monto' => $monto,
                'registra_cuenta_cts' => $registrarCuenta !== null,
                'cuenta_cts_nueva' => $registrarCuenta,
                'errores' => $errores, 'advertencias' => $advertencias,
            ];
        }

        $vigente = $this->vigente($empresa, $tipo, $anio);
        $completa = $vigente?->origen === 'excel';
        if ($completa) {
            // Quien ya tiene depósito en la CTS cargada se mantiene tal cual.
            $conDeposito = $vigente->detalles()->whereHas('depositos')->pluck('colaborador_id')->all();
            foreach ($filas as &$f) {
                if (! $f['errores'] && in_array($f['colaborador_id'], $conDeposito, true)) {
                    $f['advertencias'][] = 'Ya tiene depósito registrado: se mantiene lo guardado.';
                }
            }
            unset($f);
        }
        $validas = array_filter($filas, fn ($f) => ! $f['errores']);

        return [
            'hoja' => $leido['hoja'],
            'filas' => $filas,
            'total' => round(array_sum(array_column($filas, 'monto')), 2),
            'con_errores' => count($filas) - count($validas),
            'validas' => count($validas),
            'total_validas' => round(array_sum(array_column($validas, 'monto')), 2),
            'vigente' => $vigente ? [
                'version' => $vigente->version,
                'origen' => $vigente->origen,
                // Una CTS cargada desde Excel se completa (no se reemplaza), aunque tenga depósitos.
                'modo' => $completa ? 'completar' : 'reemplazar',
                'bloqueada' => ! $completa && $this->bloqueada($vigente),
            ] : null,
        ];
    }

    /**
     * Guarda las filas sin errores; las que tienen errores se omiten y se
     * subsanan volviendo a cargar el Excel. Si la CTS vigente ya vino de
     * Excel, la completa: agrega a quien falta y actualiza a quien aún no
     * tiene depósito. Si no, crea una nueva versión (si la vigente no tiene depósitos).
     */
    public function importar(Empresa $empresa, string $tipo, int $anio, string $ruta, string $nombreArchivo, int $usuarioId): BeneficioSocial
    {
        $vista = $this->previsualizar($empresa, $tipo, $anio, $ruta);
        $filas = array_values(array_filter($vista['filas'], fn ($f) => ! $f['errores']));
        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'Ninguna fila del Excel se puede guardar: corrige los errores y vuelve a cargarlo.']);
        }
        $rango = BeneficioSocialService::rango($tipo, $anio);

        return DB::transaction(function () use ($empresa, $tipo, $anio, $filas, $rango, $nombreArchivo, $usuarioId) {
            $prev = BeneficioSocial::where('empresa_id', $empresa->id)->where('tipo', $tipo)->where('anio', $anio)->where('es_version_vigente', true)->lockForUpdate()->first();

            if ($prev?->origen === 'excel') {
                $beneficio = $prev;
                $beneficio->update(['archivo_origen' => mb_substr($nombreArchivo, 0, 255), 'calculado_por' => $usuarioId, 'calculado_at' => now()]);
            } else {
                if ($prev && $this->bloqueada($prev)) {
                    throw ValidationException::withMessages(['archivo' => 'La CTS vigente ya tiene depósitos registrados; no se puede reemplazar.']);
                }
                $prev?->update(['es_version_vigente' => false]);
                $beneficio = BeneficioSocial::create([
                    'empresa_id' => $empresa->id, 'tipo' => $tipo, 'anio' => $anio, 'version' => ($prev?->version ?? 0) + 1, 'es_version_vigente' => true,
                    'total_colaboradores' => 0, 'total_bruto' => 0, 'total_neto' => 0,
                    'estado' => 'calculado', 'calculado_por' => $usuarioId, 'calculado_at' => now(),
                    'origen' => 'excel', 'archivo_origen' => mb_substr($nombreArchivo, 0, 255),
                ]);
            }

            $existentes = $beneficio->detalles()->withExists('depositos')->get()->keyBy('colaborador_id');
            foreach ($filas as $f) {
                if ($f['cuenta_cts_nueva']) {
                    // Solo si la ficha sigue sin cuenta (se vuelve a verificar dentro de la transacción).
                    Colaborador::whereKey($f['colaborador_id'])->where(fn ($q) => $q->whereNull('cts_cuenta')->orWhere('cts_cuenta', ''))
                        ->update(['cts_cuenta' => $f['cuenta_cts_nueva']]);
                }
                $existente = $existentes->get($f['colaborador_id']);
                if ($existente?->depositos_exists) {
                    continue;
                }
                $datos = $this->datosDetalle($empresa, $rango, $f);
                $existente ? $existente->update($datos) : BeneficioSocialDetalle::create(['beneficio_social_id' => $beneficio->id, ...$datos]);
            }

            $total = round((float) $beneficio->detalles()->sum('neta'), 2);
            $beneficio->update(['total_colaboradores' => $beneficio->detalles()->count(), 'total_bruto' => $total, 'total_neto' => $total]);

            return $beneficio;
        });
    }

    /** @param  array<string, mixed>  $f  Fila validada de la vista previa. */
    private function datosDetalle(Empresa $empresa, array $rango, array $f): array
    {
        $conceptos = array_filter(['SUELDO_BASICO' => $f['sueldo'], 'PROMEDIO_HORAS_EXTRA' => $f['horas_extra'], 'PROMEDIO_COMISIONES' => $f['comisiones']], fn ($m) => $m > 0);

        return [
            'colaborador_id' => $f['colaborador_id'], 'sueldo_basico' => $f['sueldo'],
            'meses' => intdiv($f['dias'], 30), 'bruta' => $f['monto'], 'bonificacion_extraordinaria' => 0, 'neta' => $f['monto'],
            'detalle_calculo' => [
                'origen' => 'excel', 'fila_excel' => $f['fila'],
                'colaborador_id' => $f['colaborador_id'], 'colaborador' => $f['colaborador'], 'legajo' => $f['legajo'], 'empresa' => $empresa->nombre_comercial,
                'periodo' => ['inicio' => $rango['inicio'], 'fin' => $rango['fin'], 'referencia' => $rango['referencia']],
                'conceptos' => $conceptos,
                'gratificacion_legal_percibida' => $f['gratificacion'], 'gratificacion_sexto' => $f['sexto'],
                'base_computable' => $f['base'], 'cts_100' => $f['cts_100'],
                'meses' => intdiv($f['dias'], 30), 'dias' => $f['dias'] % 30, 'dias_computables' => $f['dias'], 'divisor' => 360,
                'tasa' => $f['tasa'], 'formula' => "({$f['base']}/360) x {$f['dias']} x {$f['tasa']}",
                'bruta' => $f['monto'], 'neta' => $f['monto'], 'importe_estimado_sujeto_revision' => null, 'importe_aprobable' => $f['monto'],
                'sueldo_basico' => $f['sueldo'], 'base_diaria_tope_mype' => round($f['sueldo'] / 30, 4),
                'alertas' => [], 'advertencias_excel' => array_values(array_filter($f['advertencias'], fn ($a) => ! str_starts_with($a, 'Ya tiene depósito'))), 'estado' => 'calculado',
            ],
        ];
    }

    private function vigente(Empresa $empresa, string $tipo, int $anio): ?BeneficioSocial
    {
        return BeneficioSocial::where('empresa_id', $empresa->id)->where('tipo', $tipo)->where('anio', $anio)->where('es_version_vigente', true)->first();
    }

    private function bloqueada(BeneficioSocial $beneficio): bool
    {
        return $beneficio->estado === 'pagado' || $beneficio->detalles()->whereHas('depositos')->exists();
    }

    /** Documento comparable: alfanumérico en mayúsculas y sin ceros a la izquierda (un DNI leído como número pierde el 0 inicial). */
    private static function clave(string $documento): string
    {
        return ltrim(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $documento)), '0');
    }
}
