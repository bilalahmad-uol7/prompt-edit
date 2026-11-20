<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case SignupBonus = 'signup_bonus';
    case Purchase = 'purchase';
    case GenerationCharge = 'generation_charge';

    public function label(): string
    {
        return match ($this) {
            self::SignupBonus => 'Welcome credit',
            self::Purchase => 'Credit purchase',
            self::GenerationCharge => 'Image generation',
        };
    }
}
