<?php

namespace App\Modules\Nominas\Application\TelecreditoBcp;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpFormato;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TelecreditoBcpValidator — responde una sola pregunta: ¿los datos que
 * REALMENTE participan en este ciclo, con esta cuenta de cargo, esta
 * fecha de proceso y este subtipo, alcanzan para construir la Planilla de
 * Haberes BCP? Solo LEE y REPORTA — nunca calcula nómina ni recalcula el
 * neto (Sección 43 del encargo: no tocar CalcularBoletaColaborador).
 *
 * Completamente independiente de PLAME/AFPnet/Catálogos SUNAT — no
 * importa nada de esos namespaces.
 *
 * Regla del ciclo (Sección 33): exige CERRADO como mínimo. Un ciclo
 * PAGADO también puede validarse (modo consulta histórica, observación
 * informativa) — nunca vuelve a ordenar un pago, solo permite inspeccionar
 * qué se habría generado.
 */
class TelecreditoBcpValidator
{
    private const GRUPO_CABECERA = 'CABECERA';

    private const GRUPO_TRABAJADOR = 'TRABAJADOR';

    /** Confirmados del PDF (página 2, campo 4). */
    private const SUBTIPOS_VALIDOS = ['G', 'V', 'M', 'P', 'T', '4', 'O', 'X', 'Z'];

    private const LONGITUD_CUENTA_BCP = 13;

    private const LONGITUD_CCI = 20;

    private const LONGITUD_MAX_NUMERO_DOCUMENTO = 12;

    /**
     * @return array{
     *   listo: bool,
     *   abonos: int,
     *   monto_total: string,
     *   checksum_estimado: ?string,
     *   bloqueantes: int,
     *   observaciones: int,
     *   hallazgos: array<int, array>,
     * }
     */
    public function validar(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $fechaProceso, string $subtipo): array
    {
        $hallazgos = $this->validarCabecera($ciclo, $cuentaCargo, $fechaProceso, $subtipo);

        $boletas = TelecreditoBcpCicloDatosLoader::poblacion($ciclo);

        foreach ($boletas as $boleta) {
            $hallazgos = [...$hallazgos, ...$this->validarTrabajador($boleta, $cuentaCargo)];
        }

        $montoTotal = $boletas->reduce(fn (string $acc, Boleta $b) => bcadd($acc, (string) $b->neto_a_pagar, 2), '0.00');

        $checksum = $this->calcularChecksumEstimado($boletas, $cuentaCargo);

        $bloqueantes = collect($hallazgos)->where('severity', 'error')->count();
        $observaciones = collect($hallazgos)->where('severity', 'warning')->count();

        return [
            'listo' => $bloqueantes === 0,
            'abonos' => $boletas->count(),
            'monto_total' => $montoTotal,
            'checksum_estimado' => $checksum,
            'bloqueantes' => $bloqueantes,
            'observaciones' => $observaciones,
            'hallazgos' => $hallazgos,
        ];
    }

    // ===================== CABECERA =====================

    private function validarCabecera(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $fechaProceso, string $subtipo): array
    {
        $hallazgos = [];

        if (! in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CICLO_NO_CERRADO', 'error', 'El ciclo debe estar "cerrado" (snapshot definitivo) para generar Telecrédito.', 'Cerrar el período en Gestión de Remuneraciones.');
        } elseif ($ciclo->estado === 'pagado') {
            // Sección 33: nunca vuelve a ordenar el pago — solo informa que
            // esta es una consulta histórica sobre un ciclo ya pagado.
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CICLO_YA_PAGADO', 'warning', 'Este ciclo ya está marcado como pagado — esta validación es solo de consulta histórica, no debe usarse para ordenar un nuevo pago.', 'Verificar si realmente se necesita regenerar este archivo.');
        }

        if ($cuentaCargo->empresa_id !== $ciclo->empresa_id) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CUENTA_CARGO_OTRA_EMPRESA', 'error', 'La cuenta de cargo seleccionada no pertenece a la empresa de este ciclo.', 'Seleccionar una cuenta de cargo de la empresa correcta.');
        }

        if ($cuentaCargo->banco?->codigo !== 'bcp') {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CUENTA_CARGO_NO_BCP', 'error', 'La cuenta de cargo debe pertenecer a BCP para Telecrédito BCP Planilla de Haberes.', 'Seleccionar una cuenta de cargo cuyo banco sea BCP.');
        }

        if (! $cuentaCargo->activo) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CUENTA_CARGO_INACTIVA', 'error', 'La cuenta de cargo seleccionada está inactiva.', 'Seleccionar una cuenta de cargo activa.');
        }

        if ($cuentaCargo->uso !== 'haberes') {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CUENTA_CARGO_USO_INVALIDO', 'error', 'La cuenta de cargo seleccionada no está habilitada para pago de haberes.', 'Seleccionar una cuenta de cargo con uso "haberes".');
        }

        if (! preg_match('/^\d{'.self::LONGITUD_CUENTA_BCP.'}$/', $cuentaCargo->numero_cuenta ?? '')) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_CUENTA_CARGO_FORMATO_INVALIDO', 'error', 'La cuenta de cargo debe tener exactamente 13 dígitos numéricos.', 'Corregir el número de la cuenta de cargo.');
        }

        if (Carbon::parse($fechaProceso)->startOfDay()->lt(Carbon::today())) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_FECHA_PROCESO_INVALIDA', 'error', 'La fecha de proceso debe ser mayor o igual a la fecha actual.', 'Elegir una fecha de proceso válida.');
        }

        if (! in_array($subtipo, self::SUBTIPOS_VALIDOS, true)) {
            $hallazgos[] = $this->hallazgoGeneral('TELECREDITO_SUBTIPO_INVALIDO', 'error', "El subtipo \"{$subtipo}\" no es uno de los valores válidos de la Planilla de Haberes BCP.", 'Elegir un subtipo válido.');
        }

        return $hallazgos;
    }

    // ===================== POBLACIÓN =====================

    // ===================== POR TRABAJADOR =====================

    private function validarTrabajador(Boleta $boleta, EmpresaCuentaBancaria $cuentaCargo): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            return [];
        }

        $hallazgos = [];

        if ((float) $boleta->neto_a_pagar < 0) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_NETO_NEGATIVO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, "El neto a pagar es negativo ({$boleta->neto_a_pagar}).", 'Revisar el cálculo de esta boleta antes de generar Telecrédito.');

            return $hallazgos;
        }

        $datosPago = $boleta->datosPago;
        if (! $datosPago) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_SIN_SNAPSHOT_BANCARIO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'Datos bancarios de pago no snapshoteados para esta boleta (ciclo cerrado antes de esta preparación).', 'No inventar la cuenta usada — requiere una operación explícita de preparación para ciclos históricos.');

            return $hallazgos;
        }

        $esBcp = $datosPago->banco?->codigo === 'bcp';
        if (! $datosPago->banco_id) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_BANCO_NO_IDENTIFICABLE', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'No se pudo identificar el banco de la cuenta de este colaborador (banco no reconocido en el catálogo).', 'Normalizar el banco del colaborador en su ficha.');
        } elseif ($esBcp) {
            if (blank($datosPago->numero_cuenta_snapshot) || ! preg_match('/^\d+$/', $datosPago->numero_cuenta_snapshot)) {
                $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_CUENTA_ABONO_INVALIDA', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'La cuenta BCP del colaborador está vacía o no es numérica.', 'Completar el número de cuenta del colaborador.');
            }
            if (! TelecreditoBcpFormato::codigoTipoCuentaAbono((string) $datosPago->tipo_cuenta_snapshot, true)) {
                $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_TIPO_CUENTA_INVALIDO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'El tipo de cuenta del colaborador no es válido para BCP.', 'Completar el tipo de cuenta del colaborador.');
            }
        } else {
            if (blank($datosPago->cci_snapshot) || ! preg_match('/^\d{'.self::LONGITUD_CCI.'}$/', $datosPago->cci_snapshot)) {
                $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_CCI_INVALIDO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'El CCI del colaborador está vacío o no tiene 20 dígitos numéricos.', 'Completar el CCI del colaborador.');
            }
        }

        if (! TelecreditoBcpFormato::codigoDocumento($colaborador->tipo_documento)) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_DOCUMENTO_SIN_MAPEO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código Telecrédito BCP (solo dni/ce/pasaporte).", 'Verificar el tipo de documento del colaborador.');
        }

        if (mb_strlen((string) $colaborador->numero_documento) > self::LONGITUD_MAX_NUMERO_DOCUMENTO) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_DOCUMENTO_LONGITUD_EXCEDIDA', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'El número de documento excede los 12 caracteres que acepta Telecrédito BCP.', 'Verificar el número de documento del colaborador.');
        }

        if (blank($colaborador->apellido_paterno) || blank($colaborador->nombres)) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_NOMBRE_FALTANTE', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'Falta apellido paterno o nombres del colaborador.', 'Completar la identidad del colaborador.');
        }

        if ($datosPago->moneda_snapshot && $datosPago->moneda_snapshot !== $cuentaCargo->moneda) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_MONEDA_INCOMPATIBLE', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, "La moneda de la cuenta del colaborador ({$datosPago->moneda_snapshot}) no coincide con la moneda de la cuenta de cargo ({$cuentaCargo->moneda}).", 'BCP exige la misma moneda entre cuenta de cargo y cuenta de abono.');
        }

        if ($colaborador->fecha_nacimiento && $colaborador->fecha_nacimiento->age < 18) {
            $hallazgos[] = $this->hallazgoTrabajador('TELECREDITO_MENOR_DE_EDAD_NO_SOPORTADO', 'error', self::GRUPO_TRABAJADOR, $boleta, $colaborador, 'El colaborador es menor de edad — el correlativo de documento que exige BCP para menores no está soportado todavía.', 'Caso no soportado: gestionar este pago fuera de Telecrédito hasta definir el dato.');
        }

        return $hallazgos;
    }

    // ===================== CHECKSUM (informativo) =====================

    /**
     * Sección 14/37: suma numérica (nunca concatenación de strings) de las
     * cuentas de abono reducidas + la cuenta de cargo reducida. Usa bcmath
     * (nunca float) porque la suma de muchas cuentas de 10 dígitos puede
     * exceder rangos seguros de float. Puramente informativo para
     * inspección — la posición final del campo en el TXT sigue pendiente
     * de homologación con BCP (Sección 3).
     */
    private function calcularChecksumEstimado(Collection $boletas, EmpresaCuentaBancaria $cuentaCargo): ?string
    {
        if (! preg_match('/^\d{13}$/', $cuentaCargo->numero_cuenta ?? '')) {
            return null;
        }

        $suma = substr($cuentaCargo->numero_cuenta, 3); // CARG: quita los 3 primeros dígitos

        foreach ($boletas as $boleta) {
            $datosPago = $boleta->datosPago;
            if (! $datosPago) {
                continue;
            }

            $esBcp = $datosPago->banco?->codigo === 'bcp';
            if ($esBcp && preg_match('/^\d+$/', (string) $datosPago->numero_cuenta_snapshot) && strlen($datosPago->numero_cuenta_snapshot) >= 3) {
                $suma = bcadd($suma, substr($datosPago->numero_cuenta_snapshot, 3));
            } elseif (! $esBcp && preg_match('/^\d{20}$/', (string) $datosPago->cci_snapshot)) {
                $suma = bcadd($suma, substr($datosPago->cci_snapshot, 10));
            }
            // Cuenta inválida/faltante: ya reportada como hallazgo bloqueante
            // en validarTrabajador(); no se suma (el checksum es solo
            // informativo mientras exista algún bloqueante).
        }

        return $suma;
    }

    // ===================== Hallazgos =====================

    private function hallazgoGeneral(string $code, string $severity, string $message, string $action): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'group' => self::GRUPO_CABECERA,
            'boleta_id' => null,
            'colaborador_id' => null,
            'message' => $message,
            'action' => $action,
        ];
    }

    private function hallazgoTrabajador(string $code, string $severity, string $group, Boleta $boleta, Colaborador $colaborador, string $message, string $action): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'group' => $group,
            'boleta_id' => $boleta->id,
            'colaborador_id' => $colaborador->id,
            'colaborador_nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'message' => $message,
            'action' => $action,
        ];
    }
}
