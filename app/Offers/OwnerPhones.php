<?php

namespace App\Offers;

use App\Mail\Extraction\Patterns;
use App\Mail\Message;

/**
 * Телефоны из ответа страховой — пунктами «Показать менеджеру» админу (`Disclosures`); сами менеджеру не идут (03.11.2026,
 * владелец: «менеджеру ничего, пока админ не нажмёт»). До того на странице сделки стоял урезанный текст письма (`ReplyText`,
 * 15.10.2026), и фильтр сумм пропустил «Ваше предложение в сумме 5189000,00 является не актуальным. Максимальная ставка
 * 5500000,00» — менеджер увидел закупочную и ставку аукциона; потом — телефоны сами, без админа.
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
