<?php

namespace App\Cars;

trait HasLabels
{
    /** @return array<string, string> значение → подпись, для <select>. */
    public static function options(): array
    {
        return array_combine(array_column(self::cases(), 'value'), array_map(fn ($c) => $c->label(), self::cases()));
    }

    public static function labelOf(?string $value): ?string
    {
        return $value ? self::tryFrom($value)?->label() : null;
    }
}
