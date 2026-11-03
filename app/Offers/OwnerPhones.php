<?php

namespace App\Offers;

use App\Mail\Extraction\Patterns;
use App\Mail\Message;

/**
 * Из ответа страховой менеджеру — только телефоны владельца (03.11.2026, владелец: «письма страховой менеджер не видит ни при
 * каких условиях»). До того на странице сделки стоял урезанный текст письма (`ReplyText`, 15.10.2026), и фильтр сумм пропустил
 * «Ваше предложение в сумме 5189000,00 является не актуальным. Максимальная ставка 5500000,00» — без «руб» сумма не узнавалась,
 * менеджер увидел закупочную и ставку аукциона. Урезание текста не годится в принципе: ни слова из письма, только номер.
 */
final class OwnerPhones
{
    /** Телефоны из ответа до подписи, кроме бесплатных 8-800 страховой. @return list<string> */
    public static function for(Message $message): array
    {
        return array_values(array_filter(Patterns::phones($message->replyText()),
            fn (string $p) => ! str_starts_with(preg_replace('/\D+/', '', $p), '7800')));
    }
}
