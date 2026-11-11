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

    public const MAX_BYTES = 65536;

    /**
     * The endpoint is public like the page it reports from; the visitor cookie and the throttle guard it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape of the batch is checked here. The fields of an event are cleaned by the recorder exactly like
     * Analytics::track() cleans its arguments, so one bad value drops that value or event, never the whole batch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['array'],
            'events.*.kind' => ['required', Rule::enum(ClientEventKind::class)],
            'events.*.path' => ['required', 'string', 'starts_with:/'],
            'events.*.age' => ['sometimes'],
            'events.*.referrer' => ['sometimes'],
            'events.*.url' => ['sometimes'],
            'events.*.percent' => ['sometimes'],
            'events.*.width' => ['sometimes'],
            'events.*.seconds' => ['sometimes'],
            'events.*.name' => ['sometimes'],
            'events.*.value' => ['sometimes'],
            'events.*.properties' => ['sometimes'],
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

    /**
     * Refuse a body over 64 KB with 413 before it is validated or recorded. The web server limit is what stops a huge
     * body from being read at all. A chunked request sends no length, so its body is measured.
     */
    protected function prepareForValidation(): void
    {
        if ((int) $this->header('Content-Length') > self::MAX_BYTES || strlen($this->getContent()) > self::MAX_BYTES) {
            abort(413);
        }
    }

    public function validateResolved(): void
    {
        parent::validateResolved();
    }
}
