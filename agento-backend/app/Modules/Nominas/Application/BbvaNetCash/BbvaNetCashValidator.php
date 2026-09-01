<?php

namespace App\Modules\Nominas\Application\BbvaNetCash;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashFormato;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;

/**
 * BbvaNetCashValidator — responde una sola pregunta: ¿los datos que
 * REALMENTE participan en este ciclo, con esta cuenta de cargo y este
 * subtipo, alcanzan para construir el archivo BBVA Net Cash? Solo LEE y
 * REPORTA — nunca calcula nómina ni recalcula el neto.
 *
 * Completamente independiente de TelecreditoBcpValidator/PLAME/AFPnet —
 * no importa nada de esos namespaces (mismo criterio de aislamiento por
 * integración bancaria/estatal ya establecido en el proyecto).
 *
 * Regla del ciclo: exige CERRADO como mínimo. Un ciclo PAGADO también
 * puede validarse (consulta histórica) — nunca vuelve a ordenar un pago.
 */
class BbvaNetCashValidator
{
    private const GRUPO_CABECERA = 'CABECERA';

    private const GRUPO_TRABAJADOR = 'TRABAJADOR';

    private const LONGITUD_CCI = 20;

    private const LONGITUDES_CUENTA_BBVA = [18, 20];

    private const LONGITUD_MAX_NUMERO_DOCUMENTO = 12;

    /**
     * @return array{
     *   listo: bool,
     *   abonos: int,
     *   monto_total: string,
     *   bloqueantes: int,
     *   observaciones: int,
     *   hallazgos: array<int, array>,
     * }
     */
    /** @param array<int, int> $boletaIds */
    public function validar(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $subtipo, array $boletaIds = []): array
    {
        $hallazgos = $this->validarCabecera($ciclo, $cuentaCargo, $subtipo);

        $boletas = BbvaNetCashCicloDatosLoader::poblacion($ciclo, $subtipo, $boletaIds);

        foreach ($boletas as $boleta) {
            $hallazgos = [...$hallazgos, ...$this->validarTrabajador($boleta)];
        }

        $montoTotal = $boletas->reduce(fn (string $acc, Boleta $b) => bcadd($acc, (string) $b->neto_a_pagar, 2), '0.00');

        $bloqueantes = collect($hallazgos)->where('severity', 'error')->count();
        $observaciones = collect($hallazgos)->where('severity', 'warning')->count();

        return [
            'listo' => $bloqueantes === 0,
            'abonos' => $boletas->count(),
            'monto_total' => $montoTotal,
            'bloqueantes' => $bloqueantes,
            'observaciones' => $observaciones,
            'hallazgos' => $hallazgos,
        ];
    }

    // ===================== CABECERA =====================

    private function validarCabecera(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $subtipo): array
    {
        $hallazgos = [];

        if (! in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CICLO_NO_CERRADO', 'error', 'El ciclo debe estar "cerrado" (snapshot definitivo) para generar BBVA Net Cash.', 'Cerrar el período en Gestión de Remuneraciones.');
        } elseif ($ciclo->estado === 'pagado') {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CICLO_YA_PAGADO', 'warning', 'Este ciclo ya está marcado como pagado — esta validación es solo de consulta histórica, no debe usarse para ordenar un nuevo pago.', 'Verificar si realmente se necesita regenerar este archivo.');
        }

        if ($cuentaCargo->empresa_id !== $ciclo->empresa_id) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CUENTA_CARGO_OTRA_EMPRESA', 'error', 'La cuenta de cargo seleccionada no pertenece a la empresa de este ciclo.', 'Seleccionar una cuenta de cargo de la empresa correcta.');
        }

        if ($cuentaCargo->banco?->codigo !== 'bbva') {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CUENTA_CARGO_NO_BBVA', 'error', 'La cuenta de cargo debe pertenecer a BBVA para generar BBVA Net Cash.', 'Configurar/seleccionar una cuenta de cargo cuyo banco sea BBVA.');
        }

        if (! $cuentaCargo->activo) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CUENTA_CARGO_INACTIVA', 'error', 'La cuenta de cargo seleccionada está inactiva.', 'Activar o seleccionar otra cuenta de cargo.');
        }

        if ($cuentaCargo->uso !== 'haberes') {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CUENTA_CARGO_USO_INVALIDO', 'error', 'La cuenta de cargo seleccionada no está habilitada para pago de haberes.', 'Seleccionar una cuenta de cargo con uso "haberes".');
        }

        if (! in_array(strlen($cuentaCargo->numero_cuenta ?? ''), self::LONGITUDES_CUENTA_BBVA, true) || ! preg_match('/^\d+$/', $cuentaCargo->numero_cuenta ?? '')) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_CUENTA_CARGO_FORMATO_INVALIDO', 'error', 'La cuenta de cargo BBVA debe tener 18 o 20 dígitos numéricos.', 'Corregir el número de la cuenta de cargo.');
        }

        if (! in_array($cuentaCargo->moneda, ['PEN', 'USD'], true)) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_MONEDA_CARGO_INVALIDA', 'error', 'La moneda de la cuenta de cargo debe ser PEN o USD.', 'Corregir la moneda de la cuenta de cargo.');
        }

        if (! in_array($subtipo, BbvaNetCashFormato::SUBTIPOS_VALIDOS, true)) {
            $hallazgos[] = $this->hallazgoGeneral('BBVA_SUBTIPO_INVALIDO', 'error', "El subtipo \"{$subtipo}\" no es válido — debe ser \"5\" (Planilla) o \"4\" (RH/Locación de Servicios).", 'Elegir un subtipo válido.');
        }

        return $hallazgos;
    }

    // ===================== POR TRABAJADOR =====================

    private function validarTrabajador(Boleta $boleta): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            return [];
        }

        $hallazgos = [];

        if ((float) $boleta->neto_a_pagar < 0) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_NETO_NEGATIVO', 'error', $boleta, $colaborador, "El neto a pagar es negativo ({$boleta->neto_a_pagar}).", 'Revisar el cálculo de esta boleta antes de generar BBVA Net Cash.');

            return $hallazgos;
        }

        $datosPago = $boleta->datosPago;
        if (! $datosPago) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_SIN_SNAPSHOT_BANCARIO', 'error', $boleta, $colaborador, 'Datos bancarios de pago no snapshoteados para esta boleta (ciclo cerrado antes de esta preparación).', 'No inventar la cuenta usada — requiere una operación explícita de preparación para ciclos históricos.');

            return $hallazgos;
        }

        if (! $datosPago->banco_id) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_BANCO_NO_IDENTIFICABLE', 'error', $boleta, $colaborador, 'No se pudo identificar el banco de la cuenta de este colaborador (banco no reconocido en el catálogo).', 'Normalizar el banco del colaborador en su ficha.');
        } elseif ($datosPago->banco?->codigo === 'bbva') {
            if (blank($datosPago->numero_cuenta_snapshot) || ! preg_match('/^\d+$/', $datosPago->numero_cuenta_snapshot) || ! in_array(strlen($datosPago->numero_cuenta_snapshot), self::LONGITUDES_CUENTA_BBVA, true)) {
                $hallazgos[] = $this->hallazgoTrabajador('BBVA_CUENTA_ABONO_INVALIDA', 'error', $boleta, $colaborador, 'La cuenta BBVA del colaborador está vacía o no tiene 18 ni 20 dígitos numéricos.', 'Completar/corregir el número de cuenta del colaborador.');
            }
        } else {
            // Interbancario: Agento exige el CCI completo (20 dígitos) de
            // forma más estricta que el propio macro — la fórmula oficial
            // de validación de BBVA (columna S de la hoja "Detalle") NO
            // comprueba longitud cuando el tipo de abono es "I", solo
            // cuando es "P". Decisión deliberada de Agento (PRECISIÓN),
            // no una regla demostrada del macro.
            if (blank($datosPago->cci_snapshot) || ! preg_match('/^\d{'.self::LONGITUD_CCI.'}$/', $datosPago->cci_snapshot)) {
                $hallazgos[] = $this->hallazgoTrabajador('BBVA_CCI_INVALIDO', 'error', $boleta, $colaborador, 'El CCI del colaborador está vacío o no tiene 20 dígitos numéricos.', 'Completar el CCI del colaborador.');
            }
        }

        if (! BbvaNetCashFormato::codigoDocumento($colaborador->tipo_documento)) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_DOCUMENTO_SIN_MAPEO', 'error', $boleta, $colaborador, "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código BBVA Net Cash.", 'Verificar el tipo de documento del colaborador.');
        }

        if (mb_strlen((string) $colaborador->numero_documento) > self::LONGITUD_MAX_NUMERO_DOCUMENTO) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_DOCUMENTO_LONGITUD_EXCEDIDA', 'error', $boleta, $colaborador, 'El número de documento excede los 12 caracteres que acepta BBVA Net Cash.', 'Verificar el número de documento del colaborador.');
        }

        if (blank($colaborador->apellido_paterno) || blank($colaborador->nombres)) {
            $hallazgos[] = $this->hallazgoTrabajador('BBVA_NOMBRE_FALTANTE', 'error', $boleta, $colaborador, 'Falta apellido paterno o nombres del colaborador.', 'Completar la identidad del colaborador.');
        }

        return $hallazgos;
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

    private function hallazgoTrabajador(string $code, string $severity, Boleta $boleta, Colaborador $colaborador, string $message, string $action): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'group' => self::GRUPO_TRABAJADOR,
            'boleta_id' => $boleta->id,
            'colaborador_id' => $colaborador->id,
            'colaborador_nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'message' => $message,
            'action' => $action,
        ];
    }
}
