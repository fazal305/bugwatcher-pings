<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', Password::min(8)],
            'age_confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
            'age_confirmed.accepted' => 'You must be 16 or older to create an account.',
        ];
    }
}
