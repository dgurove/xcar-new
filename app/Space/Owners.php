<?php

namespace App\Space;

use App\Vendors\Vendor;

/**
 * Кто за что отвечает на конвейере главной CRM (04.11.2026, владелец). В системе исполнителей нет: «наш ход» — XCar целиком,
 * уведомления — всем админам. Здесь — кого рисовать у этапа: вид работы → id людей (на проде и в локальной базе одни).
 * Владелец собирается раздать работу Кати Лизе и Алине — передача правкой строки. Менеджеры, логист и исполнители вывоза
 * сюда не входят: их конвейер берёт из самих записей (кто оценивает, чья сделка, чей вывоз).
 */
final class Owners
{
    private const ANDREY = 4;

    private const ALINA = 21;

    private const LIZA = 22;

    private const KATYA = 30;

    public const WORK = [
        'drafts' => self::LIZA,      // заведение руками, «Из писем»
        'floor' => self::ALINA,      // закупочная руками
        'price' => self::ANDREY,     // цена продажи, фото, срок, кому показывать, «Отдать на оценку»
        'bids' => self::ANDREY,      // выбор подтверждения
        'claims' => self::ANDREY,    // «К запросу» по галерее
        'reprice' => self::ANDREY,   // переоценка: разобрать, ответить поставщику, менеджер не ответил про закупочную
        'letters' => self::ANDREY,   // письма и ответы поставщику Альфы и Т-Страхования (`LETTER_VENDORS`)
        'deals' => self::KATYA,      // наш ход в сделках: счета, оплаты, письма поставщикам, разбор ответов
        'pickup' => self::KATYA,     // назначить вывоз
        'docs' => self::LIZA,        // документы на вывоз СДЭКом
    ];

    /** Чьи письма о сделке пишет и разбирает Андрей (владелец 05.11.2026: «Андрей по Альфе и Т-Страхованию, Катя по Совкому»). */
    private const LETTER_VENDORS = ['%альфа%', '%т-страх%'];

    public static function of(string $work): int
    {
        return self::WORK[$work];
    }

    /** Вендоры, чьи письма — у Андрея (`LETTER_VENDORS`), id. */
    public static function letterVendors(): array
    {
        return Vendor::where(function ($q) {
            foreach (self::LETTER_VENDORS as $name) {
                $q->orWhere('name', 'ilike', $name);
            }
        })->pluck('id')->all();
    }

    /** Чей наш ход в сделке — по делу из `Position::todo()` (ключ группы «Ваш ход»); `$letters` — вендор из `letterVendors`. */
    public static function forTodo(string $key, bool $letters): int
    {
        return match (true) {
            in_array($key, ['reprice', 'reprice-answer', 'price-ask'], true) => self::of('reprice'),
            $letters && in_array($key, ['letter', 'bounced', 'reply'], true) => self::of('letters'),
            default => self::of('deals'),
        };
    }
}
