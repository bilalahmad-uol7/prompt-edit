<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImageSize: string
{
    use HasOptions;

    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square',
            self::Landscape => 'Landscape',
            self::Portrait => 'Portrait',
        };
    }
}
