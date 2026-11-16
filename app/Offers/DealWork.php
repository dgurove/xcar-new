<?php

namespace App\Offers;

use App\Space\Conveyor;
use App\Workflow\Position;
use App\Workflow\WaitsFor;

/**
 * Работа со сделками вкладками (09.11.2026, владелец: «Ваш ход» — «безумно неудобно»: со схемы на главной не попасть в «Написать
 * поставщику», начальнику не скинуть ссылку на машины, по которым Альфа написала «ГОТС поступили на реализацию»). Одно правило
 * для вкладок «Сделок» CRM и схемы на главной (`Space\Conveyor`), чтобы они не расходились:
 * - `section` — раздел «Ваш ход» по делу `Position::todo` (`?todo=`), у сделки одно дело — сумма разделов равна пилюле;
 * - `waitStep` — чего ждём от поставщика или менеджера по блоку маршрута (`?what=` во «Ждём …», подэтап схемы).
 */
final class DealWork
{
    /** Разделы «Ваш ход» по порядку; первой вкладкой и по умолчанию — «Все» (`ALL`, 10.11.2026: все дела группами, чтобы не пропустили). */
    public const SECTIONS = [
        'reply' => 'Разобрать ответ',
        'write' => 'Написать поставщику',
        'request' => 'Заявка поставщику',
        'invoice' => 'Счёт',
        'reprice' => 'Переоценка',
        'call' => 'Позвонить менеджеру',
        'step' => 'Шаг сделки',
    ];

    public const ALL = 'all';

    /** Блок маршрута, которого нет в `BLOCKS`, — «Прочее» во «Ждём …». */
    public const OTHER = 'other';

    /** Чего ждём — ключи подэтапов схемы, подписи там же (`Conveyor::STEPS`). */
    public const WAITS = [
        'supplier' => ['s-agree', 's-requisites', 's-paid', 's-docs'],
        'manager' => ['m-consent', 'm-buyer', 'm-car', 'm-fee', 'm-pickup', 'm-scans'],
    ];

    /**
     * Блок маршрута сделки → подэтап: [ход поставщика, ход менеджера, наш ход без своего дела (`stage:*`)]. У вендоров пути
     * разные — общий; чего нет в таблице, на схеме не считается, во «Ждём …» — «Прочее». Оплату менеджера уточняет схема
     * сделки (`managerStep`).
     */
    public const BLOCKS = [
        'Согласование с поставщиком' => ['s-agree', 'm-consent', 'write'],
        'Реквизиты для счёта' => ['s-requisites', 'm-buyer', 'invoice'],
        'Оплата' => ['s-paid', 'm-car', 'paid'],
        'Получение автомобиля' => ['s-docs', 'm-pickup', 'settle'],
        'Договор' => ['s-docs', 'm-scans', 'settle'],
        'Оформление документов' => ['s-docs', 'm-scans', 'settle'],
        'Расчёт с поставщиком' => ['s-docs', 'm-scans', 'settle'],
        'Взяли под себя' => ['s-docs', 'm-scans', 'settle'],
        'Оформляем на нас' => ['s-docs', 'm-scans', 'settle'],
        // Заявки поставщику — только у Каркаде, его на схеме нет.
        'Заявка у поставщика' => ['s-docs', 'm-scans', null],
        'Покупатель от поставщика' => ['s-docs', 'm-scans', null],
    ];

    /**
     * Раздел «Ваш ход» по ключу дела (`Position::todo`). Переоценка — с «менеджер не ответил про закупочную», как на схеме;
     * реквизиты покупателя перед письмом — в «Написать поставщику»: без них письмо не уходит.
     */
    public static function section(string $todo): string
    {
        return match (true) {
            $todo === 'reply' => 'reply',
            in_array($todo, ['letter', 'bounced', 'requisites'], true) => 'write',
            str_starts_with($todo, 'request:') => 'request',
            str_starts_with($todo, 'gap:') => 'invoice',
            in_array($todo, ['reprice', 'reprice-answer', 'price-ask'], true) => 'reprice',
            $todo === 'consent' => 'call',
            default => 'step',
        };
    }

    /** Подэтапы блока маршрута позиции (`BLOCKS`) или null. @return array{0: string, 1: string, 2: ?string}|null */
    public static function block(Position $position): ?array
    {
        return self::BLOCKS[$position->stage?->block?->name ?? $position->stage?->name] ?? null;
    }

    /** Чего ждём по сделке: подэтап поставщика или менеджера; наш ход и блок не из таблицы — null. */
    public static function waitStep(Position $position, Deal $deal): ?string
    {
        $block = self::block($position);

        return match (true) {
            ! $block => null,
            $position->waitsFor() === WaitsFor::Supplier => $block[0],
            $position->waitsFor() === WaitsFor::Manager => self::managerStep($block[1], $position, $deal),
            default => null,
        };
    }

    /**
     * Ход менеджера: договор — сканы с подписями; оплата — за что платит: без покупателя счёта нет (`managerGap`), по счёту
     * ПРАЙМ или Р.О.С. — сама ТС, иначе — подбор XCar по ссылке (`DealScheme::paysCar`).
     */
    public static function managerStep(string $byBlock, Position $position, Deal $deal): string
    {
        return match (true) {
            str_contains(mb_strtolower($position->stage->name), 'договор') => 'm-scans',
            $byBlock !== 'm-car' => $byBlock,
            $deal->managerGap() => 'm-buyer',
            $deal->schemeOf()->paysCar() => 'm-car',
            default => 'm-fee',
        };
    }

    /** Подпись того, чего ждём (подэтап схемы), «Прочее» — блок не из таблицы. */
    public static function waitTitle(string $step): string
    {
        return Conveyor::STEPS[$step][0] ?? 'Прочее';
    }
}
