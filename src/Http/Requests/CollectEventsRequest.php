<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Requests;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ClientEventKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectEventsRequest extends FormRequest
{
    public const MAX_EVENTS = 20;

    /**
     * The endpoint is public like the page it reports from; the visitor cookie and the throttle guard it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['array'],
            'events.*.kind' => ['required', Rule::enum(ClientEventKind::class)],
            'events.*.path' => ['required', 'string', 'starts_with:/', 'max:512'],
            'events.*.age' => ['nullable', 'integer', 'min:0'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'events.*.name' => ['nullable', 'string', 'max:128'],
            'events.*.value' => ['nullable', 'numeric'],
            'events.*.properties' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    protected function prepareForValidation(): void {}

    public function validateResolved(): void
    {
        parent::validateResolved();
    }
}
