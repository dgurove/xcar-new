<?php

namespace App\Offers\Actions;

use App\Billing\Bank\Transaction;
use App\Billing\Listeners\AdvanceOnPayment;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Деньги пришли — шаг оплаты закрыт (17.11.2026, владелец: «если деньги уже пришли, значит счёт просто вне системы выставили,
 * не надо ждать, когда в системе счёт выставят — его тут никогда не выставят»). До того шаг «Оплата счёта» проходил только
 * оплатой нашего счёта (`AdvanceOnPayment` → `Deal::fullyPaid`), а счёта у большинства сделок нет: на «Оплате» стояло 90
 * сделок, счёт — у трёх, у 26 деньги давно пришли.
 *
 * Одна дверь «по сделке пришли деньги» — зовут `StampDealMoney` (выписка, оплата счёта или ссылки, зачёт), ручное «Продано»
 * (`SetDealDate`), вход на шаг оплаты (`AdvanceOnPayment::entered`) и прошлое (`deals:money-close`). Только когда деньги за
 * машину идут через нас (`DealDates::throughUs`: ПРАЙМ, Р.О.С., ДКП собственника — подбор) и нашего счёта менеджеру в
 * системе нет (`hasManagerInvoice`): есть счёт — его оплачивают те же деньги, как было. Шаг проходит, когда пришло не меньше
 * суммы сделки (`Deal::base`) или «Продано» вписали руками (человек знает, что деньги пришли); меньше — шаг стоит, долг
 * «пришло N из M» (`DealDebt`).
 * «Страховой напрямую» (за машину менеджер платит поставщику сам): дату оплаты вписали руками — шаг тоже проходит.
 */
final class MoneyIn
{
    public function __construct(private AdvanceOnPayment $advance, private TakeExit $take) {}

    /** @return bool прошёл ли шаг */
    public function __invoke(Deal $deal, ?User $by = null): bool
    {
        $deal->loadMissing('offer.vendor');
        if (self::closes($deal)) {
            $got = self::received($deal);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Деньги пришли без счёта'.($got > 0 ? ': '.Money::rub($got) : ', «Продано» вписано руками').' — шаг оплаты пройден']);

            return $this->advance->pass($deal, $by);
        }
        if (self::directDated($deal)) {
            $offer = $deal->offer;
            $exit = $offer->position(Track::Sale)->stage->payExit($deal);
            ($this->take)($offer, $exit, Actor::Manager, $by);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Оплата поставщику вписана руками — шаг оплаты пройден']);

            return true;
        }

        return false;
    }

    /** Закрывать ли шаг оплаты этой сделки деньгами без счёта. */
    public static function closes(Deal $deal): bool
    {
        if (! $deal->isActive() || ! DealDates::throughUs($deal) || $deal->hasManagerInvoice(sent: false) || $deal->forGarageBuyer()) {
            return false;
        }
        if (! $deal->offer?->position(Track::Sale)?->stage?->isPayStep()) {
            return false;
        }
        $base = (float) $deal->base();

        return (in_array('paid_in_at', $deal->dates_by_hand ?? [], true) && $deal->paid_in_at !== null)
            || ($base > 0 && self::received($deal) >= $base);
    }

    /** «Страховой напрямую»: менеджер платит поставщику сам, дату оплаты вписали руками, а шаг всё ждёт его платёжку. */
    public static function directDated(Deal $deal): bool
    {
        if (! $deal->isActive() || DealDates::throughUs($deal) || DealDates::soldColumn($deal) !== 'prepaid_at'
            || ! $deal->prepaid_at || ! in_array('prepaid_at', $deal->dates_by_hand ?? [], true)) {
            return false;
        }
        $stage = $deal->offer?->position(Track::Sale)?->stage;

        return $stage?->isPayStep() && $stage->payExit($deal) !== null;
    }

    /**
     * Сколько пришло за машину: входящие выписки, привязанные к сделке (не «Не наше», не от поставщика — возврат), и оплаты
     * разовых ссылок с её машиной мимо выписки (эквайринг). Строка выписки, что оплатила зачёт, — уже в первой сумме.
     */
    public static function received(Deal $deal): float
    {
        $since = $deal->created_at->copy()->subDays(30)->toDateString();
        $bank = (float) Transaction::where('deal_id', $deal->id)->where('direction', 'in')->where('state', '!=', Transaction::IGNORED)
            ->whereDate('booked_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('counterparty_inn')->orWhereNotIn('counterparty_inn', StampDealMoney::supplierInns($deal)))
            ->sum('amount');
        $links = (float) Payment::whereIn('invoice_id', $deal->credits()->select('billing_invoices.id'))
            ->where('state', PaymentState::Confirmed)->where('source', '!=', PaymentSource::Bank)->sum('amount');

        return round($bank + $links, 2);
    }
}
