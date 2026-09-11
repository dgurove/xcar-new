<?php

namespace App\Purchases;

/** Ход выкачки характеристик или фото. Gone — снято у поставщика, повторять нечего. */
enum ImportState: string
{
    case Skipped = 'skipped';
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Partial = 'partial';
    case Failed = 'failed';
    case Gone = 'gone';

    public function label(): string
    {
        return match ($this) {
            self::Skipped => 'Нет ссылки',
            self::Pending => 'В очереди',
            self::Running => 'Идёт',
            self::Done => 'Готово',
            self::Partial => 'Не всё',
            self::Failed => 'Ошибка',
            self::Gone => 'Снято у поставщика',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Failed, self::Partial, self::Gone], true);
    }
}
