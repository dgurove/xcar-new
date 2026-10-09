<?php

namespace App\Billing\Actions;

use App\Billing\Acquiring\ExistingLink;
use App\Billing\Acquiring\LinkExists;
use App\Billing\Acquiring\PayLink;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Seller;
use App\Billing\ServiceTitle;
use App\Billing\WorkDays;
use App\Offers\DealCredit;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Разовая оплата по ссылке (начальник, 05.10.2026: «доплаты вне системы, но принять хотим по ссылке») — обычный счёт
 * ПРАЙМ без сделки и машины: номер, PDF, НДС ПРАЙМ, одна строка с услугой. Ссылку заводит `KeepPayLink`, как у счетов
 * сделок, дальше всё готовое: `/pay`, чек «полный расчёт» (услуга оказана — решение владельца), возврат, сверка ЮMoney.
 * Итог счёта — с НДС сверху, если он у плательщика такой, — не больше одного платежа ЮKassa, чтобы ссылка была на весь
 * счёт. Плательщик — заведённый контрагент или новый по имени и почте; с той же почтой он уже есть — берётся он, без дубля.
 * Машина по желанию (09.10.2026): предложение системы — `offer_id`, оплата засчитывается в его сделку (`credit_deal_id`,
 * `SyncDealInvoices`); не из системы — словами в `car_title`. Ссылка по этой машине или этому плательщику уже открыта —
 * второй нет: `LinkExists` с найденной (`ExistingLink`), под замком — два админа одновременно.
 */
final class IssueServiceInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    /** @param  Party|array{name: string, email?: ?string}  $payer */
    /** `force` — «Всё равно создать»: вторая ссылка по той же машине или плательщику, доплата сверх сделки (09.10.2026). */
    public function __invoke(Party|array $payer, User $by, float $amount, string $title, ?Offer $offer = null, ?string $car = null, bool $force = false): Invoice
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if ($problem = ServiceTitle::problem($title)) {
            throw ValidationException::withMessages(['title' => $problem]);
        }
        $party = $payer instanceof Party ? $payer : $this->payer($payer);
        $rate = Seller::Prime->vatRate();
        $total = $party->vat_on_top && $rate ? round($amount * (100 + $rate) / 100, 2) : round($amount, 2);
        $max = (float) config('xcar.yookassa.max_amount');
        if ($amount <= 0 || ($max > 0 && $total > $max + 0.005)) {
            throw ValidationException::withMessages(['amount' => 'От 1 ₽ до '.Money::exact($max).($total > $amount ? ' вместе с НДС' : '').' за один платёж']);
        }

        $car = $offer ? null : (PayLink::squash((string) $car) ?: null);

        return DB::transaction(function () use ($party, $by, $amount, $title, $offer, $car, $force) {
            DB::select('select pg_advisory_xact_lock(?)', [crc32('service-link')]);
            if (! $force && ($found = ExistingLink::find($offer, $party->exists ? $party->id : null, $party->name))) {
                throw new LinkExists($found);
            }
            // Новый плательщик заводится только вместе со счётом: «ссылка уже есть» не оставляет пустого контрагента.
            $party->exists || $party->save();
            $deal = $offer ? DealCredit::dealFor($offer) : null;
            $invoice = ($this->issue)($party, $by, 'issued', ChargeKind::Service, WorkDays::add(now(), 3),
                lines: [['title' => $title, 'qty' => 1, 'unit' => 'svc', 'price' => round($amount, 2), 'kind' => ChargeKind::Service->value]], offerId: $offer?->id);
            $invoice->update(['car_title' => $car, 'credit_deal_id' => $deal?->id]);
            $offer?->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::rub($invoice->total).' — '.$party->name]);

            return $invoice;
        });
    }

    private function payer(array $payer): Party
    {
        $email = mb_strtolower(trim((string) ($payer['email'] ?? ''))) ?: null;
        $known = $email ? Party::where('is_self', false)->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first() : null;

        return $known ?? new Party(['kind' => PartyKind::Person, 'name' => PayLink::squash($payer['name']), 'email' => $email]);
    }
}
