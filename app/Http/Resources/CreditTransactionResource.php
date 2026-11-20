<?php

namespace App\Http\Resources;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'amount' => $this->amount_nanos->toDecimal(),
            'balance_after' => $this->balance_after_nanos->toDecimal(),
            'prompt' => $this->generation?->prompt,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
