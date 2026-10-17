<?php

namespace App\Logistics;

use App\Support\Money;
use App\Support\Phone;

/**
 * Текст задачи исполнителю — «Отправить исполнителю» (18.10.2026): машина, откуда и у кого, когда, куда и кто принимает,
 * своя цена, ссылка на страницу задачи (`Transport::tripUrl`) — там он отмечает «Созвонились», «Забрал», «Привёз» без входа.
 * Строками: мессенджеры показывают его как есть. Номера убытка, вендора и цены заказчику нет.
 */
final class TripText
{
    public static function for(Transport $t): string
    {
        $car = $t->car;
        $phone = Phone::normalize($t->ownerPhone());

        return implode("\n", array_filter([
            'Вывоз: '.$t->title().($car?->plate ? ', '.$car->plate : ''),
            ($from = $t->pickup_place ?? $t->fromAddress()) ? 'Откуда: '.$from : null,
            ($t->ownerName() || $phone) ? 'Контакт: '.implode(', ', array_filter([$t->ownerName(), $phone ? Phone::format($phone) : null])) : null,
            $t->pickup_at ? 'Когда: '.$t->pickup_at->translatedFormat($t->pickup_at->format('H:i') === '00:00' ? 'j F' : 'j F, H:i') : null,
            ($to = $t->destinationLabel()) ? 'Куда: '.$to : null,
            ($t->to_contact || $t->to_phone) ? 'Принимает: '.implode(', ', array_filter([$t->to_contact, $t->to_phone ? Phone::format($t->to_phone) : null])) : null,
            $t->executor_cost ? 'Оплата: '.Money::rub((int) $t->executor_cost) : null,
            $t->comment,
            $t->tripUrl() ? 'Отмечать здесь:' : null,
        ]));
    }
}
