{{-- Покупатель на странице сделки (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»): с согласия — у ДКП,
     ПРАЙМ и «страховой напрямую» (`Deal::needsBuyer`). Попросили (`buyer_asked_at`) — заголовком дело, без срока; покупателя ещё
     нет — сначала плитки «Физлицо» / «ИП» / «Организация» (поля заполняет менеджер, 13.11.2026), ниже выбор из своих
     (`cabinet.deals.contract`). Окно «Кто покупатель?» сразу после «Беру в работу» — `x-deal.buyer-ask-sheet`. Белой карточкой (11.11.2026, владелец:
     «висит в воздухе — тут как раз нужен блок»): плитки и выбор внутри неё серыми. --}}
@php
    $asked = $deal->buyer_asked_at;
    $chosen = (bool) \App\Offers\DealContract::for($deal)->buyer_user_id;
@endphp
<section class="box min-w-0">
    <h2>{{ $asked && ! $chosen ? 'Укажите покупателя' : ($deal->isGarage() ? 'Договор купли-продажи' : 'Покупатель') }}</h2>
    @if (! $chosen && $deal->isLive())<div class="mt-3"><x-deal.buyer-kind :deal="$deal"/></div>@endif
    <div class="mt-2">@include('cabinet.deals.contract', ['part' => $deal->hasContract() ? 'all' : 'buyer', 'embedded' => true])</div>
</section>
