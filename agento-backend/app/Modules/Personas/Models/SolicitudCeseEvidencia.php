<?php

namespace App\Modules\Personas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['solicitud_cese_id', 'nombre_original', 'ruta', 'mime_type', 'tamano_bytes', 'sha256'])]
class SolicitudCeseEvidencia extends Model
{
    protected $table = 'personas_solicitud_cese_evidencias';

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCese::class, 'solicitud_cese_id');
    }
}
