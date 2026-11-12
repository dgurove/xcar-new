<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Bank\Transaction;
use App\Billing\ChargeKind;
use App\Billing\Party;
use App\Billing\Robot;
use App\Billing\WorkDays;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\OfferEventType;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Поступление с VIN машины в назначении — в её идущую сделку зачётом (12.11.2026, владелец: «привязывать автоматически по vin
 * даже если сумма другая, там просто пусть считает сколько осталось»; с 01.10 все 115 входящих лежали нераспознанными,
 * у 37 VIN совпадал со сделкой — гаражные стояли на «Оплата счёта ПРАЙМ», а деньги были у нас). Счёта у гаражной до продажи
 * нет — поэтому не оплата счёта, а разовый счёт ПРАЙМ на сумму поступления с `credit_deal_id`, сразу оплаченный выпиской: дальше
 * готовое — `CreditDealOnManual` → `SyncDealInvoices` → `ApplyDealCredits` кладёт деньги в счета сделки, когда они есть
 * (меньше — у счёта остаток, больше — `Deal::creditExtra`), а без счёта держит зачётом до его выставления.
 * Не трогает: VIN нет, машина не в сделке или в нескольких идущих сделках сразу.
 */
final class MatchByVin
{
    public function __construct(private IssueInvoice $issue, private MatchTransaction $match) {}

    /** Сделка по VIN из назначения — ровно одна идущая, иначе null. */
    public static function dealFor(Transaction $tx): ?Deal
    {
        if (! $tx->isIncoming() || ! preg_match_all('/(?<![A-Z0-9])[A-HJ-NPR-Z0-9]{17}(?![A-Z0-9])/', mb_strtoupper((string) $tx->purpose), $m)) {
            return null;
        }
        $vins = collect($m[0])->filter(fn ($v) => preg_match('/\d/', $v) && preg_match('/[A-Z]/', $v))->unique()->values();
        if ($vins->isEmpty()) {
            return null;
        }
        $deals = Deal::whereIn('state', [DealState::Active, DealState::Parallel])
            ->whereHas('offer.car', fn ($c) => $c->whereIn(DB::raw('upper(vin)'), $vins->all()))->with('offer', 'buyer')->get();

        return $deals->count() === 1 ? $deals->first() : null;
    }

    /** Привязать поступление к сделке по VIN; null — не нашлась одна сделка или уже разобрано. */
    public function __invoke(Transaction $tx): ?Deal
    {
        if ($tx->state !== Transaction::UNMATCHED || ! ($deal = self::dealFor($tx)) || ! $deal->buyer) {
            return null;
        }
        $by = Robot::user();
        $title = 'Оплата ТС '.$deal->offer->titleWithYear().' по выписке от '.$tx->booked_at->format('d.m.Y');
        $invoice = DB::transaction(function () use ($tx, $deal, $by, $title) {
            $invoice = ($this->issue)(Party::forUser($deal->buyer), $by, 'issued', ChargeKind::Service, WorkDays::add(now(), 3),
                lines: [['title' => $title, 'qty' => 1, 'unit' => 'svc', 'price' => round((float) $tx->amount, 2), 'kind' => ChargeKind::Service->value]],
                offerId: $deal->offer_id);
            $invoice->update(['credit_deal_id' => $deal->id]);

            return $invoice;
        });
        ($this->match)($tx, $invoice);
        // Ссылка на оплату встала вместе со счётом (`KeepPayLink`) — счёт уже оплачен выпиской, она не нужна.
        PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->get()->each(fn (PayLink $l) => app(CancelPayLink::class)($l, $by));
        if ($tx->fresh()->state !== Transaction::MATCHED) {
            return null;
        }
        $tx->update(['note' => 'по VIN в сделку '.$deal->offer->number]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Поступление '.Money::rub((float) $tx->amount).' по VIN из выписки — зачётом в сделку']);

        // Заплатил покупатель, а не менеджер — он и покупатель сделки (`BuyerFromStatement`, 12.11.2026). Не вышло — привязка остаётся.
        try {
            app(BuyerFromStatement::class)($tx, $deal->fresh(), $by);
        } catch (\Throwable $e) {
            Log::warning('bank: покупатель из выписки по сделке '.$deal->offer->number.' — '.$e->getMessage());
        }

        return $deal;
    }
}
