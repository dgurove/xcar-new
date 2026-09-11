<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/** Чей ход на этапе. Отсюда — вид часов и подача на экранах. */
enum WaitsFor: string
{
    use HasLabels;

    case Us = 'us';
    case Manager = 'manager';
    case Supplier = 'supplier';
    case Nobody = 'nobody';

    public function label(): string
    {
        return match ($this) {
            self::Us => 'Ждём нас',
            self::Manager => 'Ждём менеджера',
            self::Supplier => 'Ждём поставщика',
            self::Nobody => 'Никого не ждём',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Us => 'urgent',
            self::Manager => 'open',
            self::Supplier => 'closed',
            self::Nobody => 'plain',
        };
    }
}
