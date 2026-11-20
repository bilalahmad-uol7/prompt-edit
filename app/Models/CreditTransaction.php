<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger of balance changes.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $generation_id
 * @property CreditTransactionType $type
 * @property Usd $amount_nanos
 * @property Usd $balance_after_nanos
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount_nanos', 'balance_after_nanos'])]
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_nanos' => Usd::class,
            'balance_after_nanos' => Usd::class,
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
