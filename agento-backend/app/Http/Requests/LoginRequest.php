<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Build the credentials array for the JWT guard, matching against
     * "email" when the submitted identifier looks like one and against
     * "username" otherwise.
     *
     * @return array{email: string, password: string}|array{username: string, password: string}
     */
    public function credentials(): array
    {
        $login = $this->validated('login');
        $campo = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $campo => $login,
            'password' => $this->validated('password'),
        ];
    }
}
