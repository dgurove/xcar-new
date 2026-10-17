<?php

namespace App\Workflow\Actions;

use App\Notifications\HotStagesNotice;
use App\Offers\DealState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Утром в 10:00 по Москве — админам одна строка «Горит: N шагов, наш ход M» со ссылкой на «Горит» (19.01.2027, вместо
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
        $hot = Position::where('track', 'sale')->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
            ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhere(fn ($x) => $x->waiting(WaitsFor::Us)));
        $total = (clone $hot)->count();
        if ($total === 0) {
            return;
        }
        Notification::send(User::withRole(Role::Admin)->get(), new HotStagesNotice($total, (clone $hot)->waiting(WaitsFor::Us)->count()));
    }
}
