<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'empresa_id' => [
                'required',
                'integer',
                'exists:empresas,id',
                function ($attribute, $value, $fail) {
                    $tieneAcceso = $this->user('api')->empresas()
                        ->where('empresas.id', $value)
                        ->exists();

                    if (! $tieneAcceso) {
                        $fail('No tienes acceso a esta empresa.');
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
                        ->where('empresa_id', $this->input('empresa_id'))
                        ->exists();

                    if (! $perteneceAEmpresa) {
                        $fail('El área seleccionada no pertenece a la empresa indicada.');
                    }
                },
            ],
        ];
    }
}
