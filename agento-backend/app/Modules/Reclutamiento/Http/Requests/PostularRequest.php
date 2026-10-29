<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Postulación pública (multipart). Los arreglos llegan como JSON en campos de
 * texto para convivir con el archivo; se decodifican antes de validar.
 */
class PostularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $decodificados = [];
        foreach (['experiencias', 'estudios', 'habilidades', 'requisitos'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor)) {
                $decodificados[$campo] = json_decode($valor, true) ?? [];
            }
        }
        $this->merge($decodificados);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('reclutamiento.cv.max_kb', 5120);

        return [
            'invitacion' => ['nullable', 'string', 'max:100'],
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => ['required', 'string', 'max:20', $this->input('tipo_documento') === 'dni' ? 'digits:8' : 'alpha_num'],
            'nombres' => ['required', 'string', 'max:120'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            'celular' => ['required', 'string', 'max:20'],
            'ubicacion' => ['nullable', 'string', 'max:150'],
            'resumen' => ['nullable', 'string', 'max:2000'],
            'pretension_salarial' => ['nullable', 'numeric', 'min:0'],
            'experiencias' => ['array', 'max:20'],
            'experiencias.*.titulo' => ['required', 'string', 'max:150'],
            'experiencias.*.institucion' => ['nullable', 'string', 'max:150'],
            'experiencias.*.fecha_inicio' => ['nullable', 'date'],
            'experiencias.*.fecha_fin' => ['nullable', 'date'],
            'experiencias.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'estudios' => ['array', 'max:20'],
            'estudios.*.titulo' => ['required', 'string', 'max:150'],
            'estudios.*.institucion' => ['nullable', 'string', 'max:150'],
            'estudios.*.fecha_inicio' => ['nullable', 'date'],
            'estudios.*.fecha_fin' => ['nullable', 'date'],
            'estudios.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'habilidades' => ['array', 'max:30'],
            'habilidades.*.nombre' => ['required', 'string', 'max:100'],
            'habilidades.*.nivel' => ['nullable', 'integer', 'min:1', 'max:5'],
            'requisitos' => ['array'],
            'requisitos.*' => ['boolean'],
            'cv' => ['required', 'file', 'mimes:pdf', "max:{$maxKb}"],
            'consentimiento' => ['accepted'],
            'consentimiento_texto' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consentimiento.accepted' => 'Debes aceptar el tratamiento de tus datos para postular.',
            'cv.max' => 'El CV no debe superar los 5 MB.',
            'cv.mimes' => 'El CV debe ser un archivo PDF.',
        ];
    }
}
