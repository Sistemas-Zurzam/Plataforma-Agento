<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credencial segura para marcación por carnet (código de barras) — ver
 * migración `create_colaborador_credenciales_acceso_table` para el porqué
 * de cada columna. `token_hash` es lo único que identifica la credencial; el
 * valor real (impreso en el carnet) nunca se persiste.
 *
 * #[ScopedBy(EmpresaScope)] hace que una búsqueda por `token_hash` de un
 * carnet de OTRA empresa simplemente no aparezca (en vez de aparecer y
 * tener que comparar empresa_id a mano) — así "código de otra empresa" y
 * "código inexistente" son indistinguibles desde el resultado de la query,
 * sin lógica adicional para no revelar a qué empresa pertenece.
 */
#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'colaborador_id', 'tipo', 'token_hash', 'estado',
    'generado_at', 'generado_por', 'revocado_at', 'revocado_por', 'motivo_revocacion',
])]
class CredencialAcceso extends Model
{
    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_REVOCADA = 'revocada';

    public const TIPO_CARNET_CODIGO_BARRAS = 'carnet_codigo_barras';

    protected $table = 'colaborador_credenciales_acceso';

    /**
     * Ningún controlador serializa este modelo directamente hoy (todos arman
     * arrays explícitos — ver ControlAccesoController) — esto es una segunda
     * barrera para que un `toArray()`/`toJson()` futuro (un Resource nuevo,
     * un `response()->json($credencial)` por descuido) no exponga el hash
     * por defecto.
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'generado_at' => 'datetime',
            'revocado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA;
    }
}
