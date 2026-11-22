{{-- «Оплата сделки» — тело окна строки «Оплат» (CRM, `admin/car/money-window`) и страницы `/offers/N/money` (XCAR Pay, шторкой):
     22.11.2026, начальник: «где редактор оплаты? нажимаешь на тачку — попадаешь в сделку», по макету — вариант А. Кто держит
     машину и чат (`admin.car.facts`), «Продажа» целиком (`x-deal.money`: цена, покупатель, счета с «Оплата получена» и
     «Оплатили без ссылки…», договор, оплаты вне счёта, вознаграждение); «Оплаты поставщику», пути, писем и прочего нет —
     они на странице сделки («Открыть сделку» у того, кто вставляет кусок). --}}
@php
    $moneyDeal = $deal ?? $garageCar?->deal;
    // Плашка держателя — как в окне машины (`admin/car/window`): чат с менеджером, «Показать менеджеру», ссылка.
    $dealChat = $moneyDeal?->isActive() && $moneyDeal->buyer;
    $personDeal = $deals ? ($garageCar?->deal ?? ($deal?->isActive() ? $deal : null)) : null;
    $holderActs = $deals && ($car->holder()['user'] ?? null) !== null;
@endphp
@include('admin.car.facts')
@if ($moneyDeal)<x-deal.money :deal="$moneyDeal" id="money" :skip="$moneyDeal->isGarage() ? ['payer'] : []"/>@endif
{{-- Шторки плашки держателя («Передать…» гаражной, публикация для других) — по первому требованию, как в окне машины. --}}
<div class="contents" data-lazy-sheets="/offers/{{ $n }}/window-sheets{{ ($car->pastDeal ?? false) && $deal ? '?deal='.$deal->id : '' }}"></div>
