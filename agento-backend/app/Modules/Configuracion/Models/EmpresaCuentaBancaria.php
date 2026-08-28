<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta bancaria de una empresa (cuenta de cargo para Telecrédito y
 * futuras integraciones) — ver migración crear_empresa_cuentas_bancarias.
 * Sin ScopedBy(EmpresaScope) a propósito, mismo criterio que Boleta/
 * CicloRemunerativo: el usuario puede administrar cuentas de CUALQUIER
 * empresa que realmente administre, no solo la activa — la autorización
 * la valida siempre el Service (tieneAccesoA), nunca un scope global.
 */
#[Fillable(['empresa_id', 'banco_id', 'tipo_cuenta', 'moneda', 'numero_cuenta', 'uso', 'es_predeterminada', 'activo'])]
class EmpresaCuentaBancaria extends Model
{
    protected $table = 'empresa_cuentas_bancarias';

    protected function casts(): array
    {
        return [
            'es_predeterminada' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }
}
