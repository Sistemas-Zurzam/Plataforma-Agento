<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AsociarPersonIdRequest extends FormRequest
{
    public function rules(): array { return ['person_id' => ['required', 'string', 'max:100']]; }
}
