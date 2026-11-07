<?php

namespace App\Offers\Actions;

use App\Offers\AudienceRules;
use App\Offers\BidState;
use App\Offers\Binding;
use App\Offers\Estimate;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Support\Ago;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Эксклюзив оценившего (02.11.2026, владелец и Андрей): админ, отдавая одному на оценку, выбрал «Потом только ему N дней»
 * (`offer_estimates.exclusive_days`) — админ проверил оценку и нажал «Взять»: машина в продаже ценой его оценки и только ему
 * (`offers.first_look_until`, N дней до 17:00 от «Взять», тем же сроком кончается приём; до 07.11.2026 — сразу после
 * оценки, без админа); не подтвердил за это время — открывается всем по той же цене, приём заново, как у
 * публикации. Подтвердил — дальше как у всех: «Есть подтверждения», выбирает админ.
 */
final class FirstLook
{
    public function __construct(private TakeEstimate $take, private UpdateOffer $update, private ChangeOfferState $change) {}

    /**
     * Админ взял оценку с эксклюзивом («Взять», `OfferEstimateController::take`). Не готово к продаже (нет фото) — только цена,
     * машина в «Оцененных»: тогда false.
     */
    public function open(Estimate $estimate, User $by): bool
    {
        $who = $estimate->user;
        $offer = ($this->take)($estimate, $by);
        if (ChangeOfferState::missing($offer) || $offer->state !== OfferState::Draft || $offer->isScheduled()) {
            return false;
        }
        $until = Binding::clampClose($offer, Slots::closeFor(now(), $estimate->exclusive_days));
        DB::transaction(function () use ($offer, $who, $until, $by) {
            ($this->update)($offer, [
                'audience_rules' => AudienceRules::normalize([['type' => 'user', 'id' => $who->id, 'delay' => 0], ['type' => 'rest', 'delay' => null]]),
                'bids_close_at' => $until, 'first_look_until' => $until, 'first_look_user_id' => $who->id,
            ], $by, ['why' => 'Только '.$who->shortName().' до '.Ago::at($until, true, words: false)]);
            ($this->change)($offer->refresh(), OfferState::Open, $by);
        });

        return true;
    }

    /** Шаг `offers:tick` — до «приём закрыт»: пять дней прошли. */
    public function due(): int
    {
        $n = 0;
        Offer::where('state', OfferState::Open)->whereNotNull('first_look_until')->where('first_look_until', '<=', now())
            ->lazyById()->each(function (Offer $offer) use (&$n) {
                try {
                    $this->end($offer);
                    $n++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }

    private function end(Offer $offer): void
    {
        DB::transaction(function () use ($offer) {
            // Подтвердил — дальше как у всех.
            if ($offer->bids()->whereIn('state', [BidState::Active, BidState::Accepted])->exists() || $offer->deal()->exists()) {
                Offer::whereKey($offer->id)->update(['first_look_until' => null, 'first_look_user_id' => null]);

                return;
            }
            // Выход — сейчас: для всех это новое предложение (каталог «новые сверху», пачка новых менеджерам).
            Offer::whereKey($offer->id)->update(['published_at' => now()]);
            ($this->update)($offer->refresh(), [
                'audience_rules' => AudienceRules::everyone(), 'first_look_until' => null, 'first_look_user_id' => null,
                'bids_close_at' => Binding::clampClose($offer, Slots::closeFor(now())),
            ], null, ['why' => 'Эксклюзив кончился без подтверждения — всем']);
        });
    }
}
