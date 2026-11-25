<?php

namespace App\Offers\Actions;

use App\Cars\Settlement;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Город подписания договора у поставщика (25.11.2026, `deals.signing_settlement_id`): менеджер на шаге оплаты (без него документ
 * об оплате не уходит) или сотрудник. Москва — по записи; другой город, а сделка уже на «Время подписания» или «Записи» — шаги записи
 * проходятся (`SkipSigningBooking`).
 */
final class SetSigningCity
{
    public function __construct(private SkipSigningBooking $skip) {}

    public function __invoke(Deal $deal, Settlement $city, User $by): Deal
    {
        if (! $deal->isActive()) {
            throw ValidationException::withMessages(['signing_city' => 'Сделка уже не идёт']);
        }
        DB::transaction(function () use ($deal, $city, $by) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if ($deal->signing_settlement_id === $city->id) {
                return;
            }
            $deal->forceFill(['signing_settlement_id' => $city->id])->save();
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Город подписания: '.$city->name.($deal->signsByAppointment() ? ', по записи' : ', без записи, в рабочее время')]);
            ($this->skip)($deal, $by);
        });

        return $deal->fresh();
    }
}
