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
        $staff = $user->garageStaff();
        // Машина у покупателя с гаражом (20.10.2026): его цена счётом сделки, без расчёта и выплаты; «Продана» — отметкой.
        $buyerCar = $car->forBuyer();
        $asBuyer = $buyerCar && ! $staff;
        $holderInvoice = $buyerCar ? $car->holderInvoice() : null;
        // Не отправленный ему счёт (кнопка в CRM, 16.11.2026) — «Счёт готовится» (`Deal::invoiceNotYet`).
        $holderInvoice = $asBuyer && $holderInvoice && ! $holderInvoice->isShownToManager() ? null : $holderInvoice;
        $holderDue = $asBuyer && $holderInvoice && $holderInvoice->remaining() - $holderInvoice->claimed() > 0;
        $n = $offer->number;
        $invoice = $car->invoice;
        // Ждёт денег сейчас: счёт по машине; у покупателя с гаражом — его счёт.
        $current = $invoice ?? $holderInvoice;
        // Правит сотрудник, пока покупатель не платил: цену, «Вернуть в продажу», «Отдали по ошибке» (20.10.2026).
        $buyerPaid = $buyerCar && $car->buyerHasPaid();
        $unpaid = $current && $current->remaining() > 0;
        $frozen = $car->isFrozen();
        // Расходы: сотрудник — с принятия (наш эвакуатор), менеджер — пока машина у него.
        $canAdd = ! $frozen && ($staff || $car->state->isWorking());
        $post = fn (string $label) => ['post', "/garage/cars/{$n}/stage", $label];
        // Менеджер везёт сам и машина ещё не у него — «Забрал» здесь же (17.10.2026, владелец: логистику он не видит,
        // `SelfPickup`); логистов — только если попросит («Нужен вывоз» в «⋯», `RequestPickup`).
        $pickup = ! $staff && $car->isWaiting() ? $offer->pickup() : null;
        // Рано — нет (Альфа после оплаты, Каркаде до согласия поставщика: `Deal::pickupHold`, его слово — в пути машины).
        // ТС у нас на стоянке или едет к нам (13.11.2026) — «Забрал» и «Попросить вывоз» нет: забирают выдачей с парковки.
        $picks = $pickup?->isOpen() && ! $pickup->taken() && $pickup->isExecutedBy($user) && ! ($car->deal?->isActive() && $car->deal->pickupHold())
            && ! $offer->onPark();
        // Шаг сделки на этой же странице (`cabinet.deals.step`) сам даёт «Автомобиль забрал» (шаг получения маршрута) и «Попросить
        // вывоз…» (`Handover::canRequest`) — плашка их не повторяет (19.11.2026, владелец: «зачем в сделке два раза кнопки действия»).
        // Доп. просьба держит сделку — вместо шага её задача (`cabinet.deals.extra-ask`), там их нет: плашка остаётся.
        $shown = $step && ! \App\Offers\ExtraAsk::holding($step['deal']) ? $step : null;
        $stepPicks = $shown && $shown['requirement'] && $shown['position']?->stage->isPickupStep();
        $stepAsks = $shown && $shown['handover']?->canRequest;

        $prep = array_values(array_filter(match (true) {
            // «Вы уверены?» (19.11.2026, `Offers\Warnings`): вернуть «Ещё не вывезли» может только сотрудник.
            $picks && ! $stepPicks => [['post', "/garage/cars/{$n}/picked", 'Забрал', \App\Offers\Warnings::TITLE, \App\Offers\Warnings::PICKED, 'Да, забрал']],
            // Расход — строкой блока «Расходы» (`garage.cars.costs`, 19.11.2026), не кнопкой плашки.
            $car->state === CarState::Repair => [$post('Готова')],
            default => [],
        }));
        $sale = array_values(array_filter(match (true) {
            $buyerCar && $car->state === CarState::Selling => [$staff ? ['post', "/garage/cars/{$n}/sold", 'Продана', 'Отметить, что ТС продана?']
                : ['post', "/garage/cars/{$n}/sold", 'Продана', \App\Offers\Warnings::TITLE, \App\Offers\Warnings::SOLD, 'Да, продана']],
            $asBuyer && $holderDue => [['emit', 'pay', 'Оплатить']],
            $staff && $buyerCar && $unpaid => [['emit', 'paid', 'Поступила']],
            $buyerCar => [],
            ! $staff && $car->state === CarState::Selling => [['emit', 'selling', 'Продаю']],
            ! $staff && $unpaid && $car->invoice_to !== 'buyer' && $current->remaining() - $current->claimed() > 0 => [['emit', 'pay', 'Оплатить']],
            $staff && $car->state === CarState::Selling => [['emit', 'sold', 'Продана']],
            // Совкомбанк: счёт ПРАЙМ покупателю выставляет сделка, расчёта по машине нет.
            $staff && $car->state === CarState::Sold && ! $invoice && ! $car->settledByDeal() => [['emit', 'settle', $car->manager ? 'Выставить счёт' : 'Закрыть расчёт']],
            $staff && $unpaid => [['emit', 'paid', 'Поступила']],
            default => [],
        }));
        // Редкое — в «⋯» своей дорожки: снять итог и аннулировать — у продажи, «Отдали по ошибке» — у гаража.
        $moreSale = array_values(array_filter([
            $staff && $car->state === CarState::Sold && ! $invoice ? ['form', 'DELETE', "/garage/cars/{$n}/sold", 'Не продана', 'Снять итог продажи?'] : null,
            // Покупатель с гаражом: в продаже, а счёт ещё не оплачен — «Оплатить» рядом с «Продана», в «⋯».
            $asBuyer && $holderDue && $car->state === CarState::Selling ? ['emit', 'pay', 'Оплатить'] : null,
            $staff && $unpaid && ! $buyerCar ? ['form', 'DELETE', "/garage/cars/{$n}/invoice", 'Аннулировать', 'Аннулировать документ? Расходы и итог снова можно будет поправить'] : null,
            $staff && $buyerCar && $car->state !== CarState::Settled && ! $buyerPaid ? ['emit', 'buyer-price', 'Изменить цену покупателю'] : null,
        ]));
        $morePrep = array_values(array_filter([
            // «Вернуть в продажу» с переоценкой (`ReturnToSale`, шторка `x-offer.resale-sheet`) — только в CRM, и сделку тоже снимает.
            // У покупателя с гаражом расходы — его, забрать машину они не мешают; мешает только его оплата.
            $staff && Surface::current() === Surface::Crm && ! $car->isSold() && ($buyerCar ? ! $buyerPaid : $car->costs->isEmpty()) ? ['emit', 'resale', 'Вернуть в продажу'] : null,
            $staff && ! $car->isSold() && ! $car->dealOpen() && ($buyerCar ? ! $buyerPaid : $car->costs->isEmpty()) ? ['form', 'DELETE', "/garage/cars/{$n}", 'Отдали по ошибке', $buyerCar ? 'Забрать ТС у покупателя и вернуть в черновики? Счёт аннулируется' : 'Вернуть ТС в черновики?'] : null,
            // «Попросить вывоз…» (10.11.2026, было «Нужен вывоз»: непонятно, что делает) — что будет, словами переспроса.
            $picks && ! $stepAsks ? ['form', 'POST', "/garage/cars/{$n}/pickup", 'Попросить вывоз…', 'Попросить вывоз? Логистика XCAR назначит исполнителя, он заберёт ТС'] : null,
            // При «Отдать» ошиблись «Уже у нас» — назад (20.10.2026, `UndoArrival`); обратное — «Уже вывезли» в дорожке «Вывоз».
            $staff && Actions\UndoArrival::allows($car) ? ['form', 'POST', "/garage/cars/{$n}/waiting", 'Ещё не вывезли', 'ТС ещё у владельца? Подготовка встанет, вывоз снова в логистике'] : null,
        ]));
        $more = [...$moreSale, ...$morePrep];
        // На сайте менеджеру — одной плашкой: этап, рядом расход (кроме подготовки, где он уже в паре с «Готова»).
        $buttons = array_values(array_filter([...$prep, ...$sale]));

        return [
            'car' => $car, 'offer' => $offer, 'n' => $n, 'user' => $user, 'staff' => $staff,
            's' => Settlement::of($car), 'invoice' => $invoice, 'buyerCar' => $buyerCar, 'buyerPaid' => $buyerPaid, 'asBuyer' => $asBuyer, 'holderInvoice' => $holderInvoice, 'holderDue' => $holderDue, 'current' => $current, 'unpaid' => $unpaid,
            'canAdd' => $canAdd, 'prep' => $prep, 'sale' => $sale, 'buttons' => $buttons, 'more' => $more, 'moreSale' => $moreSale, 'morePrep' => $morePrep,
            // Куда сотрудник может вернуть машину кнопкой в пути: пройденные этапы работы с ней.
            // «Готова» после закрытой сделки — та же продажа (`MoveCar`), туда не возвращают.
            'back' => $staff && $car->state->isWorking() && ! $frozen ? array_values(array_filter(CarState::cases(), fn (CarState $s) => $s->isWorking() && $s->order() < $car->state->order()
                && ($s !== CarState::Ready || $car->papersPending()))) : [],
            // Деньги плашкой — как только есть закупочная, до продажи крупно она: сотруднику нынешняя, менеджеру закреплённая
            // при подтверждении (16.10.2026). Считать нечего — плашки нет: «0 ₽» крупно ничего не говорит.
            // Покупателю с гаражом — всегда при сделке: без цены продажи там «Указать» (26.11.2026).
            'money' => $car->isSold() || $car->priceFor($staff) || ($asBuyer && $car->deal) || ($staff ? $car->spent() > 0 : $car->extraSpent($asBuyer ? Payer::Manager : null) > 0),
            'step' => $step,
            // Для пути: на каком шаге маршрута сделка и ждут ли ответа менеджера.
            'asks' => ! $staff && ($step['requirement'] ?? null),
            'dealBlock' => $car->dealOpen() ? ($step['position'] ?? null)?->stage->block?->name ?? $offer->stage(Track::Sale)?->block?->name : null,
        ];
    }
}
