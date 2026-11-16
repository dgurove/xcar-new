<?php

namespace App\Offers\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Seller;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Кому платят услуги подбора — ООО «ПРАЙМ» или ООО «Р.О.С.» (16.11.2026, владелец: «Плательщик платит — выбор ооо прайм или ооо
 * р.о.с.», строкой блока «Услуги подбора» «Продажи»). `deals.fee_seller`, счёт подбора перевыставляется от выбранного
 * (`SyncDealInvoices`: прежний аннулируется вместе со ссылкой; у Р.О.С. ссылки нет — `PayLink::eligible`). По счёту подбора уже
 * платили — смены нет.
 */
final class SetFeeSeller
{
    public const SELLERS = [Seller::Prime, Seller::Ros];

    public function __construct(private SyncDealInvoices $sync) {}

    /** По счёту подбора платили (или менеджер сообщил об оплате) — продавца не меняем. */
    public static function locked(Deal $deal): bool
    {
        return Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Selection)->where('direction', 'issued')
            ->where('state', '!=', InvoiceState::Void)->with(['payments', 'claims'])->get()
            ->contains(fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => ! $p->source->isSoft()));
    }

    public function __invoke(Deal $deal, User $by, Seller $seller): void
    {
        if (! in_array($seller, self::SELLERS, true)) {
            throw ValidationException::withMessages(['fee_seller' => 'Выберите ООО «ПРАЙМ» или ООО «Р.О.С.»']);
        }
        DB::transaction(function () use ($deal, $by, $seller) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if ($deal->feeSeller() === $seller) {
                return;
            }
            if (self::locked($deal)) {
                throw ValidationException::withMessages(['fee_seller' => 'По счёту за подбор уже платили — продавца не поменять']);
            }
            $deal->forceFill(['fee_seller' => $seller === Seller::Prime ? null : $seller->value])->save();
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Услуги подбора: '.($seller->config()['name'] ?? $seller->value)]);
            ($this->sync)($deal->fresh(), $by);
        });
    }
}
