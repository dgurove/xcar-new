<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Support\Plural;
use App\Telegram\Text;

/**
 * Менеджеру: шаги без срока ждут его давно — мягкое напоминание (`RemindStalled`), без «просрочено». Одно в день: одна сделка —
 * её шаг и машина, несколько — «Ждут вас: 3 сделки» и строками что где (07.11.2026).
 */
final class StalledStepNotice extends Notice
{
    private const LINES = 5;

    /** @param  list<array{0: Deal, 1: string}>  $items  сделка и что сделать */
    public function __construct(private array $items) {}

    public function label(): string
    {
        return 'Шаг давно ждёт';
    }

    public function title(): string
    {
        if ($one = $this->one()) {
            return $this->items[0][1].': '.$one->offer->titleWithYear();
        }
        $n = count($this->items);

        return 'Ждут вас: '.$n.' '.Plural::of($n, ['сделка', 'сделки', 'сделок']);
    }

    public function text(): ?string
    {
        return $this->one() ? null : implode("\n", $this->lines());
    }

    public function href(): string
    {
        return $this->one()?->href() ?? '/deals';
    }

    public function offerNumber(): ?int
    {
        return $this->one()?->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function toTelegram(): ?array
    {
        $one = $this->one();

        return $one
            ? ['title' => $this->title(), 'lines' => Text::lines($one->offer), 'button' => 'Открыть сделку']
            : ['title' => $this->title(), 'lines' => $this->lines(), 'button' => 'Открыть сделки'];
    }

    public function speaksRoute(): bool
    {
        return true;
    }

    private function one(): ?Deal
    {
        return count($this->items) === 1 ? $this->items[0][0] : null;
    }

    /** @return list<string> «Заберите автомобиль: Kia Rio, 2020», не больше пяти, дальше — «и ещё N». */
    private function lines(): array
    {
        $lines = array_map(fn (array $i) => $i[1].': '.$i[0]->offer->titleWithYear(), array_slice($this->items, 0, self::LINES));
        if (($more = count($this->items) - self::LINES) > 0) {
            $lines[] = 'и ещё '.$more;
        }

        return $lines;
    }
}
