<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Seller;
use App\Billing\ServiceTitle;
use App\Billing\WorkDays;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Разовая оплата по ссылке (начальник, 05.10.2026: «доплаты вне системы, но принять хотим по ссылке») — обычный счёт
 * ПРАЙМ без сделки и машины: номер, PDF, НДС ПРАЙМ, одна строка с услугой. Ссылку заводит `KeepPayLink`, как у счетов
 * сделок, дальше всё готовое: `/pay`, чек «полный расчёт» (услуга оказана — решение владельца), возврат, сверка ЮMoney.
 * Итог счёта — с НДС сверху, если он у плательщика такой, — не больше одного платежа ЮKassa, чтобы ссылка была на весь
 * счёт. Плательщик — заведённый контрагент или новый по имени и почте; с той же почтой он уже есть — берётся он, без дубля.
 */
final class IssueServiceInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    /** @param  Party|array{name: string, email?: ?string}  $payer */
    public function __invoke(Party|array $payer, User $by, float $amount, string $title): Invoice
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

        return ($this->issue)($party, $by, 'issued', ChargeKind::Service, WorkDays::add(now(), 3),
            lines: [['title' => $title, 'qty' => 1, 'unit' => 'svc', 'price' => round($amount, 2), 'kind' => ChargeKind::Service->value]]);
    }

    private function payer(array $payer): Party
    {
        $email = mb_strtolower(trim((string) ($payer['email'] ?? ''))) ?: null;
        $known = $email ? Party::where('is_self', false)->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first() : null;

        return $known ?? Party::create(['kind' => PartyKind::Person, 'name' => trim($payer['name']), 'email' => $email]);
    }
}
