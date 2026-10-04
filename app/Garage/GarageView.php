<?php

namespace App\Garage;

use App\Users\User;

/**
 * Всё, что рисуют о машине в гараже три экрана — страница на сайте, карточка «Гараж» в редакторе CRM и карточка строки
 * «Работа → Гараж»: деньги, что сейчас можно сделать (главные кнопки и «⋯») и шторки к ним. Адреса действий одни на оба
 * хоста (`/garage/cars/{n}/…`), поэтому разметка шторок общая.
 */
final class GarageView
{
    public static function for(Car $car, User $user, ?array $step = null): array
    {
        $offer = $car->offer;
        $staff = $user->isAdmin();
        $n = $offer->number;
        $invoice = $car->invoice;
        // Ждёт денег сейчас: выплата менеджеру (покупатель уже заплатил) или сам счёт.
        $current = $car->payoutInvoice ?? $invoice;
        $unpaid = $current && $current->remaining() > 0;
        $frozen = $car->isFrozen();
        $working = $car->state->isWorking();
        $canAdd = ! $frozen && $working || ($staff && ! $frozen && ! $car->isWaiting());
        $advance = $car->state->advance();
        $post = fn (string $label) => ['post', "/garage/cars/{$n}/advance", $label];
        // Кнопки: главная — шаг этапа, рядом — расход; у сотрудника — итог продажи, счёт, оплата.
        $buttons = array_values(array_filter(match (true) {
            ! $staff && $car->state === CarState::Delivery => [$post('Привёз'), $canAdd ? ['emit', 'cost-new', 'Расход'] : null],
            ! $staff && $car->state === CarState::Repair => [['emit', 'cost-new', 'Записать расход'], $post('Готова')],
            ! $staff && $car->state === CarState::Selling => [['emit', 'selling', 'Продаю'], $canAdd ? ['emit', 'cost-new', 'Расход'] : null],
            ! $staff && $unpaid && ! $current->isOwed() && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0 => [['emit', 'pay', 'Оплатить']],
            $staff && $working => [['emit', 'sold', 'Продана']],
            $staff && $car->state === CarState::Sold && ! $invoice => [['emit', 'settle', $car->manager ? 'Выставить счёт' : 'Закрыть расчёт']],
            $staff && $car->awaitsPayout() => [['emit', 'payout', 'Выплата менеджеру']],
            $staff && $unpaid => [['emit', 'paid', $current->isOwed() ? 'Выплатили' : 'Поступило']],
            default => [],
        }));
        $more = array_values(array_filter([
            $staff && $canAdd ? ['emit', 'cost-new', 'Записать расход'] : null,
            $staff && $advance ? ['form', 'POST', "/garage/cars/{$n}/advance", $advance[1], null] : null,
            $staff && $car->state === CarState::Sold && ! $invoice ? ['form', 'DELETE', "/garage/cars/{$n}/sold", 'Не продана', 'Снять итог продажи?'] : null,
            $staff && $unpaid ? ['form', 'DELETE', "/garage/cars/{$n}/invoice", 'Аннулировать', 'Аннулировать документ? Расходы и итог снова можно будет поправить'] : null,
            $staff && $working && $car->costs->isEmpty() ? ['form', 'DELETE', "/garage/cars/{$n}", 'Отдали по ошибке', 'Вернуть ТС в черновики?'] : null,
        ]));

        return [
            'car' => $car, 'offer' => $offer, 'n' => $n, 'user' => $user, 'staff' => $staff,
            's' => Settlement::of($car), 'invoice' => $invoice, 'current' => $current, 'unpaid' => $unpaid,
            'canAdd' => $canAdd, 'buttons' => $buttons, 'more' => $more,
            // Деньги плашкой — сотруднику всегда (вложено, прибыль), менеджеру — с продажи: до неё его расходы — итогом списка.
            'money' => $staff ? ! $car->isWaiting() : $car->isSold(),
            'step' => $step,
            // Для пути: на каком шаге маршрута сделка и ждут ли ответа менеджера.
            'asks' => ! $staff && ($step['requirement'] ?? null),
            'waitingBlock' => $car->isWaiting() ? ($step['position'] ?? null)?->stage->block?->name ?? $offer->stage()?->block?->name : null,
        ];
    }
}
