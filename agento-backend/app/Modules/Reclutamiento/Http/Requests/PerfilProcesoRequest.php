<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PerfilProcesoRequest extends FormRequest
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
            'umbral_ats' => ['required', 'integer', 'min:0', 'max:100'],
            'pesos' => ['required', 'array'],
            'pesos.ats' => ['required', 'integer', 'min:0', 'max:100'],
            'pesos.psicometria' => ['required', 'integer', 'min:0', 'max:100'],
            'pesos.entrevistas' => ['required', 'integer', 'min:0', 'max:100'],
            'requisitos' => ['array', 'max:30'],
            'requisitos.*.tipo' => ['required', Rule::in(['excluyente', 'deseable'])],
            'requisitos.*.descripcion' => ['required', 'string', 'max:300'],
            'competencias' => ['required', 'array', 'min:1', 'max:20'],
            'competencias.*.nombre' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'competencias.*.nivel' => ['required', 'integer', 'min:1', 'max:5'],
            'competencias.*.peso' => ['required', 'integer', 'min:1', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $pesos = (array) $this->input('pesos', []);
            if (array_sum(array_map('intval', $pesos)) !== 100) {
                $v->errors()->add('pesos', 'Los pesos de ATS, psicometría y entrevistas deben sumar 100.');
            }
            $competencias = (array) $this->input('competencias', []);
            if ($competencias !== [] && array_sum(array_map(fn ($c) => (int) ($c['peso'] ?? 0), $competencias)) !== 100) {
                $v->errors()->add('competencias', 'Los pesos de las competencias deben sumar 100.');
            }
        });
    }
}
