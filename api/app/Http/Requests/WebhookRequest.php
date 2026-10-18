<?php

namespace App\Http\Requests;

use App\Models\WebhookEndpoint;
use App\Webhooks\UrlGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $endpoint = $this->route('webhook');
        $kind = $creating ? $this->input('kind') : $endpoint?->kind;

        return [
            'kind' => $creating ? ['required', Rule::in(WebhookEndpoint::KINDS)] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'url' => [$creating ? 'required' : 'sometimes', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) use ($kind) {
                if (is_string($kind) && in_array($kind, WebhookEndpoint::KINDS, true) && ($problem = app(UrlGuard::class)->problem($kind, $value))) {
                    $fail($problem);
                }
            }],
            'min_level' => ['sometimes', Rule::in(WebhookEndpoint::LEVELS)],
            'environments' => ['sometimes', 'array', 'min:1'],
            'environments.*' => ['distinct', Rule::in(WebhookEndpoint::ENVIRONMENTS)],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.prohibited' => "The kind can't be changed. Create a new alert instead.",
            'environments.min' => 'Choose at least one environment.',
            'cooldown_seconds.max' => 'The cooldown can be at most 86,400 seconds (one day).',
        ];
    }
}
