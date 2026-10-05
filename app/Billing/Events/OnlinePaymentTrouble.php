<?php

namespace App\Billing\Events;

use App\Billing\Acquiring\AcquiringPayment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * По ссылке что-то пошло не так, и об этом должен узнать человек: банк отклонил оплату (`declined`), заплатили сверх
 * счёта — надо вернуть (`overpaid`), чек 54-ФЗ не пробился (`receipt`).
 */
final class OnlinePaymentTrouble
{
    use Dispatchable;

    public function __construct(public AcquiringPayment $attempt, public string $what) {}
}
