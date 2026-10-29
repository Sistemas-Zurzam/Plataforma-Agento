<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de puntajes finales: cada recálculo agrega una fila con los pesos
 * y componentes usados, de modo que cambiar la configuración del proceso no
 * reescribe resultados ya registrados.
 */
#[Fillable(['empresa_id', 'postulacion_id', 'perfil_version', 'pesos', 'componentes', 'puntaje', 'provisional'])]
class PuntajeFinal extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seleccion_puntajes_finales';

    protected function casts(): array
    {
        return ['pesos' => 'array', 'componentes' => 'array', 'puntaje' => 'float', 'provisional' => 'boolean'];
    }
}
