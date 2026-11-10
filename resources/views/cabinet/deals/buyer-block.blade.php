{{-- Покупатель на странице сделки (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»): с согласия — у ДКП,
     ПРАЙМ и «страховой напрямую» (`Deal::needsBuyer`). Попросили (`buyer_asked_at`) — заголовком дело, без срока; покупателя ещё
     нет — сначала плитки «Паспорт» / «Карточка фирмы», ниже выбор из своих (`cabinet.deals.contract`). Прислал фото — «внесём
     сами». Окно «Кто покупатель?» сразу после «Беру в работу» — `x-deal.buyer-ask-sheet`. --}}
@php
    $asked = $deal->buyer_asked_at;
    $chosen = (bool) \App\Offers\DealContract::for($deal)->buyer_user_id;
@endphp
<section class="min-w-0">
    <h2 class="list-head flex items-baseline gap-3">
        <span class="min-w-0 flex-1">{{ $asked ? 'Укажите покупателя' : ($deal->isGarage() ? 'Договор купли-продажи' : 'Покупатель') }}</span>
    </h2>
    @if ($deal->buyer_scans_at)
        <div class="list"><div class="row"><x-ui.row-icon name="check" size="s" tone="open"/><span class="min-w-0 flex-1">Реквизиты по фото у XCar<span class="row-sub">внесём сами</span></span></div></div>
    @else
        @if (! $chosen && $deal->isLive())<div class="mb-3"><x-deal.buyer-photos :deal="$deal"/></div>@endif
        @include('cabinet.deals.contract', ['part' => $deal->hasContract() ? 'all' : 'buyer', 'embedded' => true])
    @endif
</section>
