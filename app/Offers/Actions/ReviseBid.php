<?php

namespace App\Offers\Actions;

use App\Notifications\BidCorrectedNotice;
use App\Notifications\DealEventNotice;
use App\Notifications\Notify;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Изменить живое подтверждение: цену и вид — «Для покупателя» ↔ «В гараж» (22.11.2026, владелец: у подтверждения в сделку
 * цену править можно, а гаражное ни во что не превратить; прежде — `CorrectBidAmount`, только опечатка в цене, 02.11.2026).
 * Одна дверь у админа (CRM, «Изменить…» в шторке подтверждения) и у менеджера (сайт, своё, пока ждёт решения — и после
 * конца приёма):
 * - админ (`canManageCrm`): без порогов цены — решение админа; в гараж — и без галки «Можно в гараж» у ТС (окно предупреждает);
 * - менеджер: только своё; цена — зонами, как при подтверждении (`PlaceBid::checkZone`); в гараж — где ему можно
 *   (`Offer::garageAllowedFor`).
 * Меняется на месте: время подтверждения прежнее; живое контрпредложение гаснет (было к другой цене или виду). Было → стало —
 * в «Историю»; поправил админ — менеджеру `BidCorrectedNotice`, поменял менеджер — админам, как о новом подтверждении.
 */
final class ReviseBid
{
    public function __invoke(Bid $bid, BidKind $kind, ?int $amount, User $by, bool $lowOk = false): Bid
    {
        $staff = ! $bid->user->is($by);
        if ($staff && ! $by->canManageCrm()) {
            throw ValidationException::withMessages(['amount' => 'Изменить можно только своё подтверждение']);
        }
        if (! in_array($kind, [BidKind::Buyer, BidKind::Garage], true)) {
            throw ValidationException::withMessages(['amount' => 'Так подтверждение не изменить']);
        }
        [$was, $wasKind] = DB::transaction(function () use (&$bid, $kind, &$amount, $by, $staff, $lowOk) {
            $offer = Offer::whereKey($bid->offer_id)->lockForUpdate()->firstOrFail();
            $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
            if ($bid->state !== BidState::Active || ! in_array($bid->kind, [BidKind::Buyer, BidKind::Garage], true)) {
                throw ValidationException::withMessages(['amount' => 'Изменить можно только подтверждение, что ждёт решения']);
            }
            if ($kind === BidKind::Garage) {
                if (! $staff && ! $offer->garageAllowedFor($by)) {
                    throw ValidationException::withMessages(['amount' => 'Это ТС в гараж не берут']);
                }
                $amount = null;
            } else {
                if (! $amount || $amount < 1) {
                    throw ValidationException::withMessages(['amount' => 'Впишите цену']);
                }
                $staff || PlaceBid::checkZone($offer, $amount, $lowOk);
            }
            [$was, $wasKind] = [$bid->amount, $bid->kind];
            if ($wasKind === $kind && (int) $was === (int) $amount) {
                return [$was, $wasKind];
            }
            app(MakeCounter::class)->withdraw($offer, $bid, $by);
            $bid->update(['kind' => $kind, 'amount' => $amount]);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Подтверждение '.$bid->user->shortName().': '.self::word($wasKind, $was).' → '.self::word($kind, $amount)
                .($staff ? '' : ', изменил сам')]);

            return [$was, $wasKind];
        });
        if ($wasKind === $bid->kind && (int) $was === (int) $bid->amount) {
            return $bid;
        }
        $bid->load('offer', 'user');
        $staff
            ? $bid->user->notify(new BidCorrectedNotice($bid, $was, $wasKind, $by))
            : Notify::toStaff(DealEventNotice::bidRevised($bid, $wasKind, $was), $by);

        return $bid;
    }

    /** «в гараж» или «1 150 000» — для «Истории» и уведомлений. */
    public static function word(BidKind $kind, ?int $amount): string
    {
        return $kind === BidKind::Garage ? 'в гараж' : Money::plain((int) $amount);
    }
}
