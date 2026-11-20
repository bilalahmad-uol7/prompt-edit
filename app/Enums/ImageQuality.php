<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImageQuality: string
{
    use HasOptions;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
