<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Same rules as submitting, except the prompt may still be empty while the user types.
 */
class QuoteGenerationRequest extends StoreGenerationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'prompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
        ];
    }
}
