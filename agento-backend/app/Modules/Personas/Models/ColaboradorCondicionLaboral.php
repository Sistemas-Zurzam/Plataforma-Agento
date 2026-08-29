<?php

namespace App\Modules\Personas\Models;

use App\Modules\Configuracion\Models\Afp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'colaborador_id', 'regimen_laboral', 'tipo_contrato', 'categoria_trabajador', 'sistema_previsional', 'afp_id', 'tipo_comision', 'es_trabajador_confianza', 'contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra', 'vigencia_desde',
])]
class ColaboradorCondicionLaboral extends Model
{
    protected $table = 'colaborador_condiciones_laborales';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'es_trabajador_confianza' => 'boolean',
            'contabilizar_tardanzas' => 'boolean',
            'contabilizar_faltas' => 'boolean',
            'contabilizar_horas_extra' => 'boolean',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }

    /**
     * La condición laboral REALMENTE vigente en una fecha específica —
     * nunca el valor mutable actual de Colaborador. Es append-only (solo
     * `vigencia_desde`, sin `vigencia_hasta`): la fila aplicable es la más
     * reciente cuya vigencia ya empezó para esa fecha. Usado por
     * ProcesarAsistenciaDiaria y CalcularBoletaColaborador para resolver
     * trabajador de confianza (V3 P3/T1) respetando cambios a mitad de
     * período — nunca deben leer colaborador.es_trabajador_confianza
     * directamente para reconstruir un día/período pasado.
     */
    public static function vigenteEn(int $colaboradorId, string $fecha): ?self
    {
        return static::where('colaborador_id', $colaboradorId)
            ->whereDate('vigencia_desde', '<=', $fecha)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();
    }
}
