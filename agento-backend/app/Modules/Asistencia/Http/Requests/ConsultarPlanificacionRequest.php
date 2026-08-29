<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultarPlanificacionRequest extends FormRequest
{
    /**
     * axios serializa un booleano JS en query string como el TEXTO
     * "true"/"false" (nunca "1"/"0") — la regla 'boolean' de Laravel solo
     * acepta [true, false, 0, 1, '0', '1'], por lo que "true" como cadena
     * la rechazaba con 422 antes de llegar al controller (bug real
     * detectado en producción: GET /asistencia/planificacion?solo_rotativos=true).
     * Se normaliza acá antes de validar, igual que el controller ya hace
     * con $request->boolean() al leerlo.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('solo_rotativos')) {
            $this->merge(['solo_rotativos' => $this->boolean('solo_rotativos')]);
        }
    }

    public function rules(): array
    {
        return [
            // Cualquier fecha dentro de la semana a consultar — el servicio
            // resuelve lunes-domingo a partir de ella.
            'semana' => ['required', 'date'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'solo_rotativos' => ['nullable', 'boolean'],
            'sede_id' => ['nullable', 'integer'],
        ];
    }
}
