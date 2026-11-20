<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Data\TokenRates;
use App\Services\Pixelforge\Data\TokenUsage;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string|null $client_request_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property Usd $estimated_min_charge_nanos
 * @property Usd $estimated_max_charge_nanos
 * @property Usd|null $provider_cost_nanos
 * @property Usd|null $charged_nanos
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property CarbonImmutable|null $retry_at
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'user_id', 'client_request_id', 'provider_id', 'status',
    'prompt', 'size', 'quality', 'reference_images',
    'estimated_min_charge_nanos', 'estimated_max_charge_nanos', 'provider_cost_nanos', 'charged_nanos',
    'rates', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens',
    'error_code', 'error_message', 'submit_attempts', 'retry_at',
    'image_url', 'image_url_expires_at', 'image_path',
    'submitted_at', 'started_at', 'completed_at', 'last_synced_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min_charge_nanos' => Usd::class,
            'estimated_max_charge_nanos' => Usd::class,
            'provider_cost_nanos' => Usd::class,
            'charged_nanos' => Usd::class,
            'retry_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Generations that have not reached a final state yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    public function isInFlight(): bool
    {
        return ! $this->status->isTerminal();
    }

    /**
     * Credit reserved against the user's balance until the generation settles.
     */
    public function heldAmount(): Usd
    {
        return $this->isInFlight() ? $this->estimated_max_charge_nanos : Usd::zero();
    }

    public function tokenUsage(): ?TokenUsage
    {
        if ($this->output_tokens === null) {
            return null;
        }

        return new TokenUsage(
            inputText: (int) $this->input_text_tokens,
            inputImage: (int) $this->input_image_tokens,
            output: $this->output_tokens,
            reasoning: (int) $this->reasoning_tokens,
        );
    }

    public function tokenRates(): ?TokenRates
    {
        return $this->rates === null ? null : TokenRates::fromArray($this->rates);
    }

    /**
     * The best URL to show the image: our stored copy, else Pixelforge's URL while it is still valid.
     */
    public function displayImageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('pixelforge.images.disk'))->url($this->image_path);
        }

        if ($this->image_url !== null && $this->image_url_expires_at?->isFuture()) {
            return $this->image_url;
        }

        return null;
    }

    public function imageHasExpired(): bool
    {
        return $this->status === GenerationStatus::Succeeded
            && $this->image_path === null
            && ($this->image_url_expires_at === null || $this->image_url_expires_at->isPast());
    }
}
