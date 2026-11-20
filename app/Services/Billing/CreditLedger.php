<?php

namespace App\Services\Billing;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that changes a user's balance. Every change is written to the
 * append-only credit_transactions ledger together with the resulting balance.
 */
class CreditLedger
{
    public function credit(User $user, Usd $amount, CreditTransactionType $type): CreditTransaction
    {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(fn () => $this->record(
            User::query()->lockForUpdate()->findOrFail($user->id),
            $amount,
            $type,
        ));
    }

    /**
     * Charge the owner of a generation. Must run inside the transaction that settles the generation,
     * while the generation row is locked. The (generation_id, type) unique index makes a second
     * charge for the same generation fail loudly instead of double billing.
     */
    public function chargeForGeneration(Generation $generation, Usd $amount): void
    {
        $user = $generation->user_id === null ? null : User::query()->lockForUpdate()->find($generation->user_id);

        if ($user === null || $amount->isZero()) {
            return;
        }

        $this->record($user, $amount->negate(), CreditTransactionType::GenerationCharge, $generation);
    }

    private function record(User $user, Usd $amount, CreditTransactionType $type, ?Generation $generation = null): CreditTransaction
    {
        $balance = $user->balance_nanos->plus($amount);

        $user->forceFill(['balance_nanos' => $balance])->save();

        return $user->creditTransactions()->create([
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amount,
            'balance_after_nanos' => $balance,
        ]);
    }
}
