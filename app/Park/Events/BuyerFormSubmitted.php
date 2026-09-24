<?php

namespace App\Park\Events;

use App\Park\Pass;
use Illuminate\Foundation\Events\Dispatchable;

/** Покупатель отправил анкету по ссылке: пропуск заведён, страховой ушёл запрос на подтверждение. */
final class BuyerFormSubmitted
{
    use Dispatchable;

    public function __construct(public Pass $pass) {}
}
