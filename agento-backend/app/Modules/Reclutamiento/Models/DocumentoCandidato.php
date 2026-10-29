<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * Archivo inmutable en almacenamiento privado. Un CV nuevo crea otra fila;
 * la postulación conserva la referencia al CV con el que se postuló.
 */
#[ScopedBy([EmpresaScope::class])]
#[Hidden(['ruta', 'disco', 'sha256'])]
#[Fillable(['empresa_id', 'candidato_id', 'tipo', 'disco', 'ruta', 'nombre_original', 'mime', 'tamano', 'sha256', 'eliminado_at'])]
class DocumentoCandidato extends Model
{
    protected $table = 'seleccion_documentos';

    protected function casts(): array
    {
        return ['eliminado_at' => 'datetime', 'tamano' => 'integer'];
    }
}
