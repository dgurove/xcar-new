<?php

namespace App\Offers\Actions;

use App\Notifications\BuyerAskNotice;
use App\Offers\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Покупатель — дело менеджера сразу после «Беру в работу» (10.11.2026, владелец: «реквизиты требовать, как только взял в работу,
 * а не после того, как страховая запросила»; письмо страховой «пришлите реквизиты» — наша задача: отправить то, что он дал).
 * `ask` — после согласия: покупателя нет или он без данных — `buyer_asked_at`, срок — 17:00 следующего дня, менеджеру
 * `BuyerAskNotice`. `settle` — после каждой правки покупателя и конца сделки: всё есть — дело гаснет.
 */
final class AskBuyer
{
    public function ask(Deal $deal, bool $notify = true, ?Carbon $now = null): bool
    {
        $deal->refresh();
        if ($deal->buyer_asked_at || ! $deal->buyer || $deal->buyerGap() === null) {
            return false;
        }
        $now ??= now();
        $deal->update(['buyer_asked_at' => $now,
            'buyer_due_at' => $now->copy()->setTimezone('Europe/Moscow')->addDay()->setTime(17, 0)->setTimezone(config('app.timezone'))]);
        if ($notify) {
            $id = $deal->id;
            DB::afterCommit(function () use ($id) {
                $deal = Deal::with(['buyer', 'offer'])->find($id);
                if ($deal?->buyer_asked_at && $deal->buyer) {
                    $deal->buyer->notify(new BuyerAskNotice($deal));
                }
            });
        }

        return true;
    }

    public static function settle(Deal $deal): void
    {
        if ($deal->buyer_asked_at && (! $deal->isActive() || $deal->buyerGap() === null)) {
            $deal->forceFill(['buyer_asked_at' => null, 'buyer_due_at' => null])->saveQuietly();
        }
    }
}
