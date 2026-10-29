<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarProcesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reclutador_user_id' => ['sometimes', 'integer'],
            'cargo' => ['sometimes', 'string', 'min:3', 'max:150'],
            'descripcion' => ['sometimes', 'string', 'min:30', 'max:4000'],
            'modalidad' => ['sometimes', Rule::in(SolicitudPersonal::MODALIDADES)],
            'ubicacion' => ['sometimes', 'nullable', 'string', 'max:150'],
            'mostrar_salario' => ['sometimes', 'boolean'],
            'empresa_confidencial' => ['sometimes', 'boolean'],
            'fecha_cierre_postulacion' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
