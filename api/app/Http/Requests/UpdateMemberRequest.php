<?php

namespace App\Http\Requests;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }
}
