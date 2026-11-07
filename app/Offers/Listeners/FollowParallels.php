<?php

namespace App\Offers\Listeners;

use App\Offers\Actions\CloseParallels;
use App\Offers\Parallels;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;

/**
 * Главная сделка сделала шаг (07.11.2026) — параллельные за ней (`Parallels::follow`): шаги поставщика до реквизитов — те
 * же, свои шаги менеджера — свои. Главная прошла оплату — переключаться больше не на кого: параллельные закрываются.
 */
final class FollowParallels
{
    public function __construct(private CloseParallels $close) {}

    public function handle(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! Parallels::of($e->offer)->count()) {
            return;
        }
        if ($e->from?->isPayStep() && Parallels::rank($e->to) > Parallels::rank($e->from)) {
            ($this->close)($e->offer, $e->by, 'по сделке заплатили');

            return;
        }
        Parallels::follow($e->offer, $e->by);
    }
}
