<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePermisosRequest extends FormRequest
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
            'asignaciones' => ['required', 'array'],
            'asignaciones.*' => ['array'],
            'asignaciones.*.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleIds = array_keys($this->input('asignaciones', []));
            $existentes = Role::whereIn('id', $roleIds)->pluck('id')->all();

            foreach ($roleIds as $roleId) {
                if (! in_array((int) $roleId, $existentes, true)) {
                    $validator->errors()->add('asignaciones', "El rol {$roleId} no existe.");
                }
            }
        });
    }
}
