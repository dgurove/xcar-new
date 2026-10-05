<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\ServiceTitle;
use App\Billing\WorkDays;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Разовая оплата по ссылке (начальник, 05.10.2026: «доплаты вне системы, но принять хотим по ссылке») — обычный счёт
 * ПРАЙМ без сделки и машины: номер, PDF, НДС ПРАЙМ, одна строка с услугой. Ссылку заводит `KeepPayLink`, как у счетов
 * сделок, дальше всё готовое: `/pay`, чек «полный расчёт» (услуга оказана — решение владельца), возврат, сверка ЮMoney.
 * Сумма — не больше одного платежа ЮKassa, чтобы ссылка была на весь счёт.
 */
final class IssueServiceInvoice
{
    public function __construct(private IssueInvoice $issue) {}

    public function __invoke(Party $party, User $by, float $amount, string $title): Invoice
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        if ($problem = ServiceTitle::problem($title)) {
            throw ValidationException::withMessages(['title' => $problem]);
        }
        $max = (float) config('xcar.yookassa.max_amount');
        if ($amount <= 0 || ($max > 0 && $amount > $max + 0.005)) {
            throw ValidationException::withMessages(['amount' => 'От 1 ₽ до '.Money::exact($max).' за один платёж']);
        }

        return ($this->issue)($party, $by, 'issued', ChargeKind::Service, WorkDays::add(now(), 3),
            lines: [['title' => $title, 'qty' => 1, 'unit' => 'svc', 'price' => round($amount, 2), 'kind' => ChargeKind::Service->value]]);
    }
}
