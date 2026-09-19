<?php

namespace App\Telegram\Messages;

use App\Support\Money;
use App\Support\Surface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Закрытие месяца посчитано: сколько ТС, на сколько, по каким контрагентам — выставлять на экране. */
final class ClosingReady extends Message
{
    public function __construct(private CarbonInterface $month, private Collection $items) {}

    protected function title(): string
    {
        return 'Закрытие '.mb_strtolower($this->month->translatedFormat('F'));
    }

    protected function lines(): array
    {
        $byParty = $this->items->groupBy(fn ($i) => $i['party']?->name ?? 'без плательщика')->map(fn ($rows) => $rows->sum('amount'))->sortDesc();

        return [
            $this->items->count().' счетов на '.Money::rub($this->items->sum('amount')),
            ...$byParty->take(6)->map(fn ($sum, $name) => $name.' — '.Money::rub($sum))->values()->all(),
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Закрытие месяца', 'url' => Surface::Park->url('/money/closing?month='.$this->month->format('Y-m'))];
    }
}
