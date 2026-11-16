<?php

namespace App\Offers;

use App\Workflow\Position;

/**
 * Подэтапы схемы на главной CRM (`Space\Conveyor`) по блоку маршрута сделки: чего ждём от поставщика или менеджера. Вкладки
 * сделки в «Предложениях» раскладывает `DealTabs` (16.11.2026; прежние разделы «Ваш ход» и `section` отсюда сняты).
 */
final class DealWork
{
    /**
     * Блок маршрута сделки → подэтап: [ход поставщика, ход менеджера, наш ход без своего дела (`stage:*`)]. У вендоров пути
     * разные — общий; чего нет в таблице, на схеме не считается. Оплату менеджера уточняет схема
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

    /** Подэтапы блока маршрута позиции (`BLOCKS`) или null. @return array{0: string, 1: string, 2: ?string}|null */
    public static function block(Position $position): ?array
    {
        return self::BLOCKS[$position->stage?->block?->name ?? $position->stage?->name] ?? null;
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
}
