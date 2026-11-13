{{-- Карточки сделки в третьей дорожке редактора — одни у «Сделки» и «Гаража» (07.10.2026, владелец: «гараж — это тоже как
     сделка, приводи к общему виду»): деньги и ДКП, если он у схемы есть. Заметка — в правой колонке под «Показом» (12.10.2026). --}}
@php $compact ??= false; @endphp
{{-- Окно (`compact`, 12.11.2026, владелец: «закупочная несколько раз»): ссылка менеджеру — пилюлей вверху окна, в деньгах нет
     числа шапки (`CarView::moneySkip`), ДКП — на странице, а что в нём не готово, говорит строка дела в шаге. --}}
@unless ($compact)<x-deal.person-link :deal="$deal"/>@endunless
<x-deal.money :deal="$deal" id="money" :skip="$compact ? $car->moneySkip() : []"/>
{{-- В окне — покупатель, пока его данных нет (13.11.2026, владелец: «не могу паспорт подгрузить» — блок был только на странице):
     выбор и «Данные для счёта» со сканом паспорта; данные есть — не рисуется, ДКП целиком — на странице. --}}
@if ($compact)
    @if ($deal->isLive() && ($deal->collectsBuyer() || $deal->contract?->buyer_user_id) && $deal->buyerGap() !== null)<x-deal.invoice-buyer :deal="$deal" :title="$deal->hasContract() ? 'Покупатель для договора' : 'Покупатель для счёта'"/>@endif
@elseif ($deal->hasContract())<x-deal.contract :deal="$deal"/>@elseif ($deal->collectsBuyer() || $deal->contract?->buyer_user_id)<x-deal.invoice-buyer :deal="$deal"/>@endif
