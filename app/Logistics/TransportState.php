<?php

namespace App\Logistics;

use App\Cars\HasLabels;

/**
 * Ход вывоза (17.10.2026): без исполнителя (решает админ) → у логиста (Татьяна назначает своего исполнителя и цену) →
 * назначен (ждём созвона) → созвонились (дата и место есть) → вывезена («Забрал») → привёз. Перевозка A → B
 * (`TransportKind::Move`) созвона не знает: назначена → в пути → привёз.
 */
enum TransportState: string
{
    use HasLabels;

    case New = 'new';
    case Logist = 'logist';
    case Assigned = 'assigned';
    case Agreed = 'agreed';
    case Moving = 'moving';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public const OPEN = [self::New, self::Logist, self::Assigned, self::Agreed, self::Moving];

    public function label(): string
    {
        return match ($this) {
            self::New => 'Без исполнителя',
            self::Logist => 'У логиста',
            self::Assigned => 'Назначен',
            self::Agreed => 'Созвонились',
            self::Moving => 'Вывезена',
            self::Delivered => 'Привёз',
            self::Cancelled => 'Отменён',
        };
    }

    /** Цвет слова в строке: ждёт решения — тревожно, в пути — акцент, конец — серый. */
    public function tone(): string
    {
        return match ($this) {
            self::New, self::Logist => 'text-urgent',
            self::Assigned, self::Agreed => 'text-ink',
            self::Moving => 'text-accent-text',
            self::Delivered, self::Cancelled => 'text-ink-muted',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, self::OPEN, true);
    }

    /** Машину уже забрали у владельца: кто и откуда — поздно менять. */
    public function picked(): bool
    {
        return in_array($this, [self::Moving, self::Delivered], true);
    }
}
