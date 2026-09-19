<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza una contraseña vacía a null: el campo es opcional en este
     * formulario ("dejar en blanco para no cambiarla"), pero un string
     * vacío no debe activar la regla min:6.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('password') === '') {
            $this->merge(['password' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $usuario = $this->route('usuario');

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6'],
            'username' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($usuario),
            ],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($usuario),
            ],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'empresa_ids' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    if (! in_array($this->user('api')->empresa_id, $value, true)) {
                        $fail('El usuario debe permanecer en la empresa activa.');
                    }
                },
            ],
            'empresa_ids.*' => [
                'integer',
                'distinct',
                'exists:empresas,id',
                function ($attribute, $value, $fail) {
                    $actor = $this->user('api');

                    if (! $actor->esAdministradorGlobal()
                        && ! $actor->empresas()->where('empresas.id', $value)->exists()) {
                        $fail('No tienes acceso a una de las empresas seleccionadas.');
                    }
                },
            ],
            'area_id' => [
                'nullable',
                'integer',
                'exists:areas,id',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return;
                    }

                    $perteneceAEmpresa = Area::withoutGlobalScope(EmpresaScope::class)
                        ->where('id', $value)
                        ->whereIn('empresa_id', $this->input('empresa_ids', []))
                        ->exists();

                    if (! $perteneceAEmpresa) {
                        $fail('El área seleccionada no pertenece a la empresa activa.');
                    }
                },
            ],
        ];
    }
}
