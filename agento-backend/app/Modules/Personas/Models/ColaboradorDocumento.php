<?php

namespace App\Modules\Personas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['colaborador_id', 'tipo', 'nombre_original', 'ruta', 'mime_type', 'tamano_bytes', 'subido_por'])]
class ColaboradorDocumento extends Model
{
    protected $table = 'colaborador_documentos';

    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class); }
    public function autor(): BelongsTo { return $this->belongsTo(User::class, 'subido_por'); }
}
