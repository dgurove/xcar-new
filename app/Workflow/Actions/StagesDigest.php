<?php

namespace App\Workflow\Actions;

use App\Notifications\HotStagesNotice;
use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Утром в 10:00 по Москве — тем, кто ведёт сделки, одна строка «Горит: N шагов, наш ход M» со ссылкой на «Горит» (19.01.2027, вместо
 * россыпи «срок подходит» по каждой сделке). Раз в день, зовёт `offers:tick`.
 */
final class StagesDigest
{
    public function __invoke(): void
    {
        $now = now('Europe/Moscow');
        if ($now->hour < 10 || ! Cache::add('stages:digest:'.$now->toDateString(), true, now()->addDays(2))) {
            return;
        }
        // Шаги нашей оплаты поставщику не считаем (26.11.2026, владелец: «денег на счёте постоянно нет, это только нервирует»):
        // кому заплатить, смотрят во вкладке «Оплатить поставщику».
        $payOut = \App\Workflow\Stage::query()->get()->filter->paysOut()->pluck('id')->all();
        $hot = Position::where('track', 'sale')->whereHas('offer.deal', fn ($d) => $d->active())->whereNotIn('stage_id', $payOut)
            ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhere(fn ($x) => $x->waiting(WaitsFor::Us)));
        $total = (clone $hot)->count();
        if ($total === 0) {
            return;
        }
        Notification::send(Notify::dealStaff(), new HotStagesNotice($total, (clone $hot)->waiting(WaitsFor::Us)->count()));
    }
}
