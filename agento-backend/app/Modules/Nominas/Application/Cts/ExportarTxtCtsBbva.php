<?php

namespace App\Modules\Nominas\Application\Cts;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\Cts\BbvaCtsTxtBuilder;
use App\Modules\Nominas\Models\BeneficioSocial;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * TXT de depósito de CTS para BBVA (macro "CTS ONLINE") con los colaboradores
 * seleccionados. Incluye a quien tiene saldo por depositar, cálculo no
 * observado, cuenta CTS BBVA de 20 dígitos y documento DNI, CE o pasaporte.
 * Generar el archivo no registra el depósito: eso se hace con el número de
 * operación del banco (RegistrarDepositosCts).
 */
class ExportarTxtCtsBbva
{
    /**
     * @param  array<int, int>  $detalleIds
     * @return array{cuenta_cargo: ?array, incluidos: array, omitidos: array, total: float, error: ?string}
     */
    public function validar(Empresa $empresa, BeneficioSocial $beneficio, array $detalleIds): array
    {
        if ((int) $beneficio->empresa_id !== (int) $empresa->id || ! str_starts_with($beneficio->tipo, 'cts_')) {
            throw new AuthorizationException('La CTS no pertenece a la empresa activa.');
        }

        $cuenta = self::cuentaCargo($empresa);
        $incluidos = [];
        $omitidos = [];
        $detalles = $beneficio->detalles()->whereIn('id', $detalleIds)->with(['colaborador', 'depositos'])->orderBy('id')->get();
        foreach ($detalles as $detalle) {
            $c = $detalle->colaborador;
            $nombre = trim($c?->apellidos.' '.$c?->nombres);
            $cuentaCts = BbvaCtsTxtBuilder::cuenta((string) $c?->cts_cuenta);
            $pendiente = round((float) $detalle->neta - (float) $detalle->depositos->sum('importe'), 2);

            $motivo = match (true) {
                ($detalle->detalle_calculo['estado'] ?? null) === 'observado' => 'Cálculo observado: resuelve sus alertas y recalcula.',
                $pendiente <= 0 => 'Ya tiene registrado el depósito completo.',
                $cuentaCts === '' => 'No tiene cuenta CTS registrada en su ficha.',
                strlen($cuentaCts) !== 20 => 'La cuenta CTS de su ficha no tiene 20 dígitos.',
                ! str_starts_with($cuentaCts, '0011') => 'La cuenta CTS no es de BBVA.',
                ! isset(BbvaCtsTxtBuilder::TIPOS_DOI[$c->tipo_documento]) || blank($c->numero_documento) => 'Su tipo de documento no es DNI, CE ni pasaporte.',
                default => null,
            };
            if ($motivo) {
                $omitidos[] = ['detalle_id' => $detalle->id, 'colaborador' => $nombre, 'motivo' => $motivo];

                continue;
            }
            $incluidos[] = [
                'detalle_id' => $detalle->id, 'colaborador' => $nombre, 'importe' => $pendiente,
                'tipo_doi' => BbvaCtsTxtBuilder::TIPOS_DOI[$c->tipo_documento], 'numero_doi' => (string) $c->numero_documento, 'cuenta' => $cuentaCts,
            ];
        }

        return [
            'cuenta_cargo' => $cuenta ? ['id' => $cuenta->id, 'numero' => '****'.substr(BbvaCtsTxtBuilder::cuenta($cuenta->numero_cuenta), -4), 'uso' => $cuenta->uso] : null,
            'incluidos' => $incluidos,
            'omitidos' => $omitidos,
            'total' => round(array_sum(array_column($incluidos, 'importe')), 2),
            'error' => match (true) {
                ! $cuenta => 'La empresa no tiene una cuenta BBVA activa para CTS ni para haberes (Configuración → Empresas → Cuentas bancarias).',
                strlen(BbvaCtsTxtBuilder::cuenta($cuenta->numero_cuenta)) !== 20 => 'La cuenta de cargo BBVA debe tener 20 dígitos.',
                $incluidos === [] => 'Ningún colaborador seleccionado puede incluirse en el TXT.',
                default => null,
            },
        ];
    }

    /** @return array{nombre: string, contenido: string} */
    public function exportar(Empresa $empresa, BeneficioSocial $beneficio, array $detalleIds): array
    {
        $validacion = $this->validar($empresa, $beneficio, $detalleIds);
        abort_if($validacion['error'] !== null, 422, $validacion['error'] ?? '');

        $referencia = 'CTS '.($beneficio->tipo === 'cts_mayo' ? 'MAYO' : 'NOVIEMBRE').' '.$beneficio->anio;
        $contenido = BbvaCtsTxtBuilder::construir(self::cuentaCargo($empresa)->numero_cuenta, $referencia, array_map(fn ($f) => [
            'tipo_doi' => $f['tipo_doi'], 'numero_doi' => $f['numero_doi'], 'cuenta' => $f['cuenta'],
            'beneficiario' => $f['colaborador'], 'centimos' => (int) round($f['importe'] * 100),
        ], $validacion['incluidos']));

        return [
            'nombre' => 'BBVA_CTS_'.Str::upper(Str::slug($empresa->nombre_comercial, '_')).'_'.Str::upper($beneficio->tipo).'_'.$beneficio->anio.'.txt',
            'contenido' => $contenido,
        ];
    }

    /** Cuenta BBVA activa para CTS (predeterminada o única); si no hay, la de haberes. */
    public static function cuentaCargo(Empresa $empresa): ?EmpresaCuentaBancaria
    {
        foreach (['cts', 'haberes'] as $uso) {
            $cuentas = EmpresaCuentaBancaria::where('empresa_id', $empresa->id)->where('uso', $uso)->where('activo', true)
                ->whereHas('banco', fn ($q) => $q->where('codigo', 'bbva'))->get();
            $cuenta = $cuentas->firstWhere('es_predeterminada', true) ?? ($cuentas->count() === 1 ? $cuentas->first() : null);
            if ($cuenta) {
                return $cuenta;
            }
        }

        return null;
    }
}
