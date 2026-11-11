<?php

namespace App\Offers\Actions;

use App\Notifications\SigningDaysNotice;
use App\Offers\Deal;
use App\Offers\Signing;
use Illuminate\Support\Facades\DB;

/**
 * Поставщик начал готовить договор (`Signing::asksDays`, вход в «Подготовку договора») — дело менеджера «Когда покупатель сможет
 * подписать?» (`deals.signing_asked_at`, `Deal::managerTask` kind `signing`) и уведомление `SigningDaysNotice`. Ответ —
 * `SaveSigningWish`. Дни уже есть — не спрашиваем.
 */
final class AskSigningDays
{
    public function __invoke(Deal $deal, bool $notify = true): bool
    {
        $deal->refresh();
        if ($deal->signing_asked_at || ! empty($deal->signing_wish['days']) || ! $deal->buyer_id || ! $deal->isActive()) {
            return false;
        }
        $deal->update(['signing_asked_at' => now()]);
        if ($notify) {
            $id = $deal->id;
            DB::afterCommit(function () use ($id) {
                $deal = Deal::with(['buyer', 'offer'])->find($id);
                if ($deal && Signing::wishDue($deal)) {
                    $deal->buyer->notify(new SigningDaysNotice($deal));
                }
            });
        }

        return true;
    }
}
