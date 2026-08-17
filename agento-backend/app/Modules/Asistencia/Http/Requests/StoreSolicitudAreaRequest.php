<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudAreaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['horas_extra', 'permiso', 'cambio_horario', 'trabajo_remoto', 'otro'])],
            'origen' => ['required', Rule::in(['rrhh_directo', 'responsable_area', 'colaborador'])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:200'],
            'colaborador_ids.*' => ['integer'],
            'motivo' => ['required', 'string', 'max:2000'],
            'medio_recepcion' => ['nullable', Rule::in(['correo', 'whatsapp', 'verbal', 'documento', 'sistema'])],
        ];
    }
}
