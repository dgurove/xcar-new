<?php

namespace App\Workflow\Actions;

use App\Notifications\StalledStepNotice;
use App\Offers\Deal;
use App\Workflow\Position;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;

/**
 * Мягкое напоминание менеджеру (18.10.2026, владелец): шаг ждёт его без срока («Менеджер забирает автомобиль» у
 * Совкомбанка, «Укажите покупателя / цену / паспорт» на оплате) — раз в трое суток, днём, без красного и просрочки.
 * Отметка — `offer_positions.reminded_at`: у позиции без срока её больше никто не ставит.
 */
final class RemindStalled
{
    public const DAYS = 3;

    /** @return int сколько напомнили */
    public function __invoke(): int
    {
        $now = Carbon::now();
        $msk = $now->copy()->setTimezone('Europe/Moscow');
        if ($msk->hour < 10 || $msk->hour >= 20) {
            return 0;
        }
        $edge = $now->copy()->subDays(self::DAYS);
        $sent = 0;
        foreach (Position::where('track', Track::Sale)->whereNull('deadline_at')->where('entered_at', '<=', $edge)
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', $edge))
            ->with(['offer.deal.buyer', 'stage.exits'])->lazyById() as $position) {
            $deal = $position->offer?->deal;
            $ask = $deal?->buyer && $position->waitsFor() === WaitsFor::Manager ? self::ask($position, $deal->setRelation('offer', $position->offer)) : null;
            if (! $ask) {
                continue;
            }
            $position->update(['reminded_at' => $now]);
            $deal->buyer->notify(new StalledStepNotice($deal, $ask));
            $sent++;
        }

        return $sent;
    }

    /** Что сделать менеджеру на шаге без срока; null — напоминать не о чем. */
    public static function ask(Position $position, Deal $deal): ?string
    {
        return match (true) {
            $position->stage->isPickupStep() => 'Заберите автомобиль',
            $position->stage->isPayStep() => match ($deal->invoiceGap()) {
                'buyer' => 'Укажите покупателя',
                'price' => 'Укажите цену продажи',
                'passport' => 'Приложите паспорт покупателя',
                default => null,
            },
            default => null,
        };
    }
}
