<?php

namespace App\Offers\Listeners;

use App\Offers\Signing;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Вошли в «Время подписания», а подписание не в Москве (25.11.2026): записи нет — сразу «Подписание» (`Actions\SkipSigningBooking`).
 * Только входом вперёд; стоит до `Notify`, как `ApplySigningWish`: «Ваш ход: когда покупатель сможет подписать?» не уходит.
 */
final class SkipSigningBooking
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        $deal = $e->deal ?? $e->offer->deal;
        if (! $e->exit || $e->track !== Track::Sale || ! $deal?->isActive() || $deal->signsByAppointment() || ! Signing::picks($e->to->loadMissing('exits'))) {
            return;
        }
        if ($e->offer->fresh()->position(Track::Sale)?->stage_id !== $e->to->id) {
            return;
        }
        $by = $e->by ?? \App\Users\User::withRole(\App\Users\Role::Admin)->orderBy('id')->firstOrFail();
        app(\App\Offers\Actions\SkipSigningBooking::class)($deal, $by);
    }
}
