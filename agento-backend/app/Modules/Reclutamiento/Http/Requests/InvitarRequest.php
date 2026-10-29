<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\Invitacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitarRequest extends FormRequest
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
            'tipo' => ['required', Rule::in([Invitacion::INTERNA, Invitacion::BANCO])],
            'candidato_id' => ['nullable', 'integer', 'required_if:tipo,banco'],
            'email' => ['nullable', 'email', 'max:191', 'required_if:tipo,interna'],
        ];
    }
}
