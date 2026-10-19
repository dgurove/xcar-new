<?php

namespace App\Offers\Actions;

use App\Logistics\Transport;
use App\Notifications\BindingPanicNotice;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BindingPanic;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Шаг `offers:tick` (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока обязывающего предложения — прям
 * ПАНИКА!!!»): срок загорелся (`Binding::scope` panic) — ведущим логистику важное уведомление со звуком, логисту вывоза —
 * в логистике, владельцу и админам — Telegram. Один раз на срок (`binding_alerted_for`, как `bids_closed_notified_for`):
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
        $leads = null;
        $sent = 0;
        foreach ($q->lazyById() as $offer) {
            DB::table('offers')->where('id', $offer->id)->update(['binding_alerted_for' => $offer->binding_until]);
            Notification::send($leads ??= User::logisticsLeads()->get(), new BindingPanicNotice($offer));
            $t = Transport::open()->where('car_id', $offer->car_id)->whereNotNull('logist_id')->with('logist')->first();
            if ($t?->logist && ! $t->logist->leadsLogistics()) {
                $t->logist->notify(new BindingPanicNotice($offer, $t));
            }
            NotifyOwner::dispatch(new BindingPanic($offer));
            $sent++;
        }

        return $sent;
    }
}
