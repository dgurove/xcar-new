<?php

namespace App\Offers\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\ExtraAsk;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\ReplyReview;
use App\Offers\RepriceAsk;

/**
 * Переприём (26.11.2026, владелец: «возьмись и за договор с переоценкой»): что новая сделка машины берёт у прежней. Одна дверь
 * для обоих путей — прежняя ещё идёт («Отдать» другому, `Give::handOver`) или её только что отменили («Менеджер отказался»,
 * приняли резерв, `prior`).
 *
 * Про машину и поставщика — всегда: ждущие разбора ответы поставщика, договор со стороны машины (собственник или продавец ДКП,
 * договор комиссии, сумма и реквизиты поставщика, НДС), открытые доп. просьбы, переоценка (`from_deal_id` — её «за время
 * сделки» считается с прежней), при оплате нашим юрлицом — «поставщику оплачено». Про покупателя — только тому же менеджеру:
 * его счета, покупатель и плательщик договора, вопрос о новой цене. Новому менеджеру вопрос о цене гаснет: спросят его заново.
 */
final class CarryDeal
{
    /** Поля договора про машину и поставщика: одни для любого покупателя. */
    private const CAR = ['seller_party_id', 'commission_no', 'supplier_price', 'supplier_purpose', 'supplier_requisites'];

    /** Поля договора про покупателя: тому же менеджеру. */
    private const BUYER = ['buyer_user_id', 'payer', 'draft_party_id', 'invoice_without_reg_at', 'cash_part', 'city'];

    /** Поля сделки про покупателя: тому же менеджеру. */
    private const DEAL_BUYER = ['payer_party_id', 'fee_seller', 'signing_slots', 'signing_address', 'signing_at', 'signing_asked_at', 'signing_wish',
        'signing_settlement_id', 'receiver_user_id', 'receiver_name', 'receiver_phone', 'receiver_at'];

    /**
     * Прежняя сделка, когда идущей нет: последняя отменённая (не параллельная), и машина с тех пор не уходила в черновики, галерею
     * или архив — та же продажа.
     */
    public static function prior(Offer $offer): ?Deal
    {
        $last = Deal::where('offer_id', $offer->id)->whereNotNull('cancelled_at')->where('parallel', false)->latest('id')->first();
        if (! $last) {
            return null;
        }
        $left = OfferEvent::where('offer_id', $offer->id)->where('type', OfferEventType::StateChanged)->where('created_at', '>=', $last->cancelled_at)
            ->whereIn('payload->to', [OfferState::Draft->value, OfferState::Gallery->value, OfferState::Archived->value])->exists();

        return $left ? null : $last;
    }

    /** @return bool  перенесли то, что видно в окне сделки (ответы, просьбы), — окну и счётчикам перечитаться */
    public function __invoke(Deal $previous, Deal $deal): bool
    {
        if ($previous->id === $deal->id) {
            return false;
        }
        $same = $previous->buyer_id !== null && $previous->buyer_id === $deal->buyer_id;
        $deal->forceFill(['from_deal_id' => $previous->id])->save();

        $moved = ReplyReview::where('deal_id', $previous->id)->where('state', ReplyReview::PENDING)->update(['deal_id' => $deal->id]);
        // Платило поставщику наше юрлицо (ПРАЙМ / Р.О.С.) — деньги не прежнего покупателя; «страховой напрямую» и ДКП платил он сам.
        if ($previous->schemeOf() === $deal->schemeOf() && $deal->isPrime() && ($previous->supplier_paid_at || $previous->prepaid_at)) {
            $deal->forceFill(['supplier_paid_at' => $deal->supplier_paid_at ?? $previous->supplier_paid_at, 'prepaid_at' => $deal->prepaid_at ?? $previous->prepaid_at])->save();
        }
        // Его оплаченные счета и счета с его заявкой об оплате — этой сделке, иначе `SyncDealInvoices` выставил бы их заново на полную
        // сумму. Другому менеджеру — нет: это деньги прежнего.
        if ($same) {
            Invoice::where('deal_id', $previous->id)->where('direction', 'issued')->whereIn('kind', [ChargeKind::Sale, ChargeKind::Selection])
                ->where('state', '!=', InvoiceState::Void)->update(['deal_id' => $deal->id]);
        }
        $this->contract($previous, $deal, $same);
        // Открытые доп. просьбы поставщику — этой сделке. Новому менеджеру ответ поставщика показываем заново.
        $moved += ExtraAsk::open()->where('deal_id', $previous->id)
            ->update(['deal_id' => $deal->id] + ($same ? [] : ['shown_at' => null, 'remind_at' => null]));
        // Вопрос о новой цене — тому же менеджеру остаётся (и его согласие), другому гаснет: его спросят по шагу «Переоценка».
        $asks = RepriceAsk::where('deal_id', $previous->id);
        $same ? $asks->whereIn('state', [...RepriceAsk::OPEN, RepriceAsk::AGREED])->update(['deal_id' => $deal->id])
            : $asks->whereIn('state', RepriceAsk::OPEN)->update(['state' => RepriceAsk::VOID]);

        return $moved > 0;
    }

    /** Договор: сторона машины — всегда (в пустые поля), сторона покупателя — тому же менеджеру. Номер, подпись и файл — свои. */
    private function contract(Deal $previous, Deal $deal, bool $same): void
    {
        $old = DealContract::where('deal_id', $previous->id)->first();
        if ($same) {
            $deal->forceFill(array_filter($previous->only(self::DEAL_BUYER), fn ($v) => $v !== null))->save();
        }
        if (! $old) {
            return;
        }
        $fill = $previous->isDkp() === $deal->isDkp() ? $old->only(self::CAR) : [];
        $fill += $previous->seller() === $deal->seller() ? $old->only(['vat_rate']) : [];
        $fill += $same ? $old->only(self::BUYER) : [];
        $fill = array_filter($fill, fn ($v) => $v !== null);
        if (! $fill) {
            return;
        }
        $contract = DealContract::for($deal);
        $contract->forceFill(array_filter($fill, fn ($v, $k) => $contract->{$k} === null, ARRAY_FILTER_USE_BOTH))->save();
        $contract->update(['price' => DealContract::priceFor($deal->fresh(['contract']))]);
    }
}
