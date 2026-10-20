<?php

namespace App\Garage;

use App\Support\Surface;
use App\Users\User;
use App\Workflow\Track;

/**
 * Всё, что рисуют о машине в гараже экраны — страница на сайте, дорожки «Гараж» и «Продажа» в редакторе CRM и карточка
 * строки «Работа → Гараж»: деньги, что сейчас можно сделать и шторки к ним. Кнопки делятся по дорожкам (06.10.2026):
 * `prep` — подготовка («Готова», расход), `sale` — продажа и расчёт («Продаю», «Продана», счёт, оплата, выплата).
 * На сайте они идут одной плашкой (`buttons`). Адреса действий одни на оба хоста (`/garage/cars/{n}/…`).
 */
final class GarageView
{
    public static function for(Car $car, User $user, ?array $step = null): array
    {
        $offer = $car->offer;
        $staff = $user->isAdmin();
        // Машина у покупателя с гаражом (20.10.2026): его цена счётом сделки, без расчёта и выплаты; «Продана» — отметкой.
        $buyerCar = $car->forBuyer();
        $asBuyer = $buyerCar && ! $staff;
        $holderInvoice = $buyerCar ? $car->holderInvoice() : null;
        $holderDue = $asBuyer && $holderInvoice && $holderInvoice->remaining() - $holderInvoice->claimed() > 0;
        $n = $offer->number;
        $invoice = $car->invoice;
        // Ждёт денег сейчас: выплата менеджеру (покупатель уже заплатил) или сам счёт.
        $current = $car->payoutInvoice ?? $invoice;
        $unpaid = $current && $current->remaining() > 0;
        $frozen = $car->isFrozen();
        // Расходы: сотрудник — с принятия (наш эвакуатор), менеджер — пока машина у него.
        $canAdd = ! $frozen && ($staff || $car->state->isWorking());
        $post = fn (string $label) => ['post', "/garage/cars/{$n}/stage", $label];
        $cost = $canAdd ? ['emit', 'cost-new', $car->state === CarState::Repair && ! $staff ? 'Записать расход' : 'Расход'] : null;
        // Менеджер везёт сам и машина ещё не у него — «Забрал» здесь же (17.10.2026, владелец: логистику он не видит,
        // `SelfPickup`); логистов — только если попросит («Нужен вывоз» в «⋯», `RequestPickup`).
        $pickup = ! $staff && $car->isWaiting() ? $offer->pickup() : null;
        // Рано — нет (Альфа после оплаты, Каркаде до согласия поставщика: `Deal::pickupHold`, его слово — в пути машины).
        $picks = $pickup?->isOpen() && ! $pickup->state->picked() && $pickup->isExecutedBy($user) && ! ($car->deal?->isActive() && $car->deal->pickupHold());

        $prep = array_values(array_filter(match (true) {
            $picks => [['post', "/garage/cars/{$n}/picked", 'Забрал']],
            $car->state === CarState::Repair => $staff ? [$post('Готова')] : [$cost, $post('Готова')],
            default => [],
        }));
        $sale = array_values(array_filter(match (true) {
            $buyerCar && $car->state === CarState::Selling => [['post', "/garage/cars/{$n}/sold", 'Продана', 'Отметить, что ТС продана?']],
            $asBuyer && $holderDue => [['emit', 'pay', 'Оплатить']],
            $buyerCar => [],
            ! $staff && $car->state === CarState::Selling => [['emit', 'selling', 'Продаю']],
            ! $staff && $unpaid && ! $current->isOwed() && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0 => [['emit', 'pay', 'Оплатить']],
            $staff && $car->state === CarState::Selling => [['emit', 'sold', 'Продана']],
            // Совкомбанк: счёт ПРАЙМ покупателю выставляет сделка, расчёта по машине нет.
            $staff && $car->state === CarState::Sold && ! $invoice && ! $car->settledByDeal() => [['emit', 'settle', $car->manager ? 'Выставить счёт' : 'Закрыть расчёт']],
            $staff && $car->awaitsPayout() => [['emit', 'payout', 'Выплата менеджеру']],
            $staff && $unpaid => [['emit', 'paid', $current->isOwed() ? 'Выплатили' : 'Поступило']],
            default => [],
        }));
        // Редкое — в «⋯» своей дорожки: снять итог и аннулировать — у продажи, «Отдали по ошибке» — у гаража.
        $moreSale = array_values(array_filter([
            $staff && $car->state === CarState::Sold && ! $invoice ? ['form', 'DELETE', "/garage/cars/{$n}/sold", 'Не продана', 'Снять итог продажи?'] : null,
            // Покупатель с гаражом: в продаже, а счёт ещё не оплачен — «Оплатить» рядом с «Продана», в «⋯».
            $asBuyer && $holderDue && $car->state === CarState::Selling ? ['emit', 'pay', 'Оплатить'] : null,
            $staff && $unpaid ? ['form', 'DELETE', "/garage/cars/{$n}/invoice", 'Аннулировать', 'Аннулировать документ? Расходы и итог снова можно будет поправить'] : null,
        ]));
        $morePrep = array_values(array_filter([
            // «Вернуть в продажу» с переоценкой (`ReturnToSale`, шторка `x-offer.resale-sheet`) — только в CRM, и сделку тоже снимает.
            $staff && Surface::current() === Surface::Crm && ! $car->isSold() && $car->costs->isEmpty() ? ['emit', 'resale', 'Вернуть в продажу'] : null,
            $staff && ! $car->isSold() && ! $car->dealOpen() && $car->costs->isEmpty() ? ['form', 'DELETE', "/garage/cars/{$n}", 'Отдали по ошибке', 'Вернуть ТС в черновики?'] : null,
            $picks ? ['form', 'POST', "/garage/cars/{$n}/pickup", 'Нужен вывоз', 'Попросить логистов забрать ТС?'] : null,
        ]));
        $more = [...$moreSale, ...$morePrep];
        // На сайте менеджеру — одной плашкой: этап, рядом расход (кроме подготовки, где он уже в паре с «Готова»).
        $buttons = array_values(array_filter([...$prep, ...$sale, ! $staff && $car->state !== CarState::Repair && $car->state->isWorking() ? $cost : null]));

        return [
            'car' => $car, 'offer' => $offer, 'n' => $n, 'user' => $user, 'staff' => $staff,
            's' => Settlement::of($car), 'invoice' => $invoice, 'buyerCar' => $buyerCar, 'asBuyer' => $asBuyer, 'holderInvoice' => $holderInvoice, 'holderDue' => $holderDue, 'current' => $current, 'unpaid' => $unpaid,
            'canAdd' => $canAdd, 'prep' => $prep, 'sale' => $sale, 'buttons' => $buttons, 'more' => $more, 'moreSale' => $moreSale, 'morePrep' => $morePrep,
            // Куда сотрудник может вернуть машину кнопкой в пути: пройденные этапы работы с ней.
            // «Готова» после закрытой сделки — та же продажа (`MoveCar`), туда не возвращают.
            'back' => $staff && $car->state->isWorking() && ! $frozen ? array_values(array_filter(CarState::cases(), fn (CarState $s) => $s->isWorking() && $s->order() < $car->state->order()
                && ($s !== CarState::Ready || $car->papersPending()))) : [],
            // Деньги плашкой — как только есть закупочная, до продажи крупно она: сотруднику нынешняя, менеджеру закреплённая
            // при подтверждении (16.10.2026). Считать нечего — плашки нет: «0 ₽» крупно ничего не говорит.
            'money' => $car->isSold() || $car->priceFor($staff) || ($staff ? $car->spent() > 0 : $car->extraSpent($asBuyer ? Payer::Manager : null) > 0),
            'step' => $step,
            // Для пути: на каком шаге маршрута сделка и ждут ли ответа менеджера.
            'asks' => ! $staff && ($step['requirement'] ?? null),
            'dealBlock' => $car->dealOpen() ? ($step['position'] ?? null)?->stage->block?->name ?? $offer->stage(Track::Sale)?->block?->name : null,
        ];
    }
}
