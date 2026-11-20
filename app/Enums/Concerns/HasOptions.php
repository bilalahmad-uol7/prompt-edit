<?php

namespace App\Enums\Concerns;

/**
 * Turns a string-backed enum into the {value, label} list a form select or radio group needs.
 */
trait HasOptions
{
    abstract public function label(): string;

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
