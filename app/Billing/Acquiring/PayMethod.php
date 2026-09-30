<?php

namespace App\Billing\Acquiring;

/** Чем заплатили по ссылке — как называет провайдер; незнакомое показывается словом «онлайн». */
final class PayMethod
{
    public static function label(?string $method): string
    {
        return match ($method) {
            'bank_card' => 'картой',
            'sbp' => 'через СБП',
            'sberbank' => 'SberPay',
            'tinkoff_bank' => 'T-Pay',
            'yoo_money' => 'ЮMoney',
            default => 'онлайн',
        };
    }

    /** Иконка способа у строки оплаты (`x-money.line`). */
    public static function icon(?string $method): string
    {
        return match ($method) {
            'sbp' => 'sbp',
            'sberbank' => 'sberpay',
            default => 'card',
        };
    }
}
