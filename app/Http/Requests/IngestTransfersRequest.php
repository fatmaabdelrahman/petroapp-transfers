<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the SHAPE of the POST /transfers payload.
 *
 * Responsibility: ensure the request has a non-empty `events` array.
 * This is fail-fast — a missing or empty `events` key is a client error (400).
 *
 * Per-event content validation (partial-accept) is handled downstream
 * in TransferIngestionService, which can reject individual events while
 * still ingesting the rest of the batch.
 */
final class IngestTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'events.required' => 'The events field is required.',
            'events.array'    => 'The events field must be an array.',
            'events.min'      => 'At least one event must be provided.',
        ];
    }

    /**
     * Override to return 400 instead of Laravel's default 422.
     * The spec requires 400 for invalid payload shape.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Invalid payload shape.',
                'errors'  => $validator->errors(),
            ], 400)
        );
    }
}
