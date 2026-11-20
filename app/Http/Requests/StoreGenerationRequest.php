<?php

namespace App\Http\Requests;

use App\Data\GenerationInput;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own validation so invalid requests are caught before any
 * credit is held or any provider request is made.
 */
class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https', 'distinct'],
            'client_request_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to :max reference images.',
            'reference_images.*.url' => 'Reference images must be http(s) URLs.',
            'reference_images.*.distinct' => 'This reference image is listed twice (each one is billed).',
        ];
    }

    public function toInput(): GenerationInput
    {
        return new GenerationInput(
            prompt: $this->string('prompt')->toString(),
            size: $this->enum('size', ImageSize::class) ?? ImageSize::Square,
            quality: $this->enum('quality', ImageQuality::class) ?? ImageQuality::Medium,
            referenceImages: array_values(array_map(strval(...), (array) $this->validated('reference_images', []))),
            clientRequestId: $this->validated('client_request_id'),
        );
    }

    /**
     * The form always submits its URL rows. Laravel's input middleware has already trimmed them
     * and turned blank ones into null; those blank rows are not reference images.
     */
    protected function prepareForValidation(): void
    {
        $references = $this->input('reference_images');

        if (is_array($references)) {
            $this->merge(['reference_images' => array_values(array_filter($references, fn (mixed $url) => $url !== null))]);
        }
    }
}
