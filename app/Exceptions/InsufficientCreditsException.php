<?php

namespace App\Exceptions;

use App\Support\Money\Usd;
use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly Usd $available, public readonly Usd $required)
    {
        parent::__construct(sprintf(
            'This generation can cost up to %s but you have %s available.',
            $required->format(),
            $available->format(),
        ));
    }
}
