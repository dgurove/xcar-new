<?php

namespace App\Offers\Actions;

use App\Notifications\BindingPanicNotice;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BindingPanic;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Шаг `offers:tick` (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока обязывающего предложения — прям
 * ПАНИКА!!!»): срок загорелся (`Binding::scope` panic) — админам важное уведомление со звуком, владельцу и админам —
 * Telegram; модераторам (07.11.2026: продать машину они не могут, открывали 0 из 24), логисту и исполнителям — нет. Один раз на срок (`binding_alerted_for`, как `bids_closed_notified_for`):
 * продлили — загорится снова; покупатель появился — не шлём. Давно вышедшие (срок старше суток, пришли такими из писем
 * или разбора прошлого) — только красным в списках, без звонка. Днём, 9–21 по Москве.
 */
final class BindingAlarm
{
    public function __invoke(): int
    {
        $hour = (int) now('Europe/Moscow')->format('G');
        if ($hour < 9 || $hour >= 21) {
            return 0;
        }
        $q = Offer::query()->where('offers.binding_until', '>=', now()->subDay())
            ->where(fn ($w) => $w->whereNull('offers.binding_alerted_for')->orWhereColumn('offers.binding_alerted_for', '!=', 'offers.binding_until'));
        Binding::scope($q, 'panic');
        $admins = null;
        $sent = 0;
        foreach ($q->lazyById() as $offer) {
            DB::table('offers')->where('id', $offer->id)->update(['binding_alerted_for' => $offer->binding_until]);
            Notification::send($admins ??= User::withRole(Role::Admin)->whereNull('rejected_at')->get(), new BindingPanicNotice($offer));
            NotifyOwner::dispatch(new BindingPanic($offer));
            $sent++;
        }

        return $sent;
    }
}
