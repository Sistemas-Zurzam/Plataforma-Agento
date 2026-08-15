<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreAreaRequest extends FormRequest
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
            'nombre' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $yaExiste = Area::withoutGlobalScope(EmpresaScope::class)
                        ->where('empresa_id', $this->route('empresa')->id)
                        ->whereRaw('LOWER(nombre) = ?', [Str::lower(trim($value))])
                        ->exists();

                    if ($yaExiste) {
                        $fail('Ya existe un área con ese nombre en esta empresa.');
                    }
                },
            ],
        ];
    }
}
