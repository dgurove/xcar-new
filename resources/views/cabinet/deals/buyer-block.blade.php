{{-- Покупатель на странице сделки (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»): с согласия — у ДКП,
     ПРАЙМ и «страховой напрямую» (`Deal::needsBuyer`). Попросили (`buyer_asked_at`) — заголовком дело, без срока; покупателя ещё
     нет — сначала плитки «Физлицо» / «ИП» / «Организация» (поля заполняет менеджер, 13.11.2026), ниже выбор из своих
     (`cabinet.deals.contract`). Окно «Кто покупатель?» сразу после «Беру в работу» — `x-deal.buyer-ask-sheet`. Белой карточкой (11.11.2026, владелец:
     «висит в воздухе — тут как раз нужен блок»): плитки и выбор внутри неё серыми. --}}
{{-- С 16.11.2026 — карточка «Договор» под шагом (вариант А): покупатель (`withBuyer`), готовый ДКП и «Подписанный договор»
     (`withSigned`, `x-deal.signed-contract bare`) — то, что для договора, в одном месте. --}}
@php
    $asked = $deal->buyer_asked_at;
    $chosen = (bool) \App\Offers\DealContract::for($deal)->buyer_user_id;
    $withBuyer ??= true;
    $withSigned ??= false;
    $title = match (true) {
        $withBuyer && $asked && ! $chosen => 'Укажите покупателя',
        $deal->hasContract() || ! $withBuyer => $deal->isGarage() ? 'Договор купли-продажи' : 'Договор',
        default => 'Покупатель',
    };
@endphp
{{-- Заголовок — как у всех карточек страницы (13 серым, `box-title`), договор и подписанный — строками без повтора слова. --}}
<section class="box min-w-0">
    <h2 class="box-title">{{ $title }}</h2>
    @php
        // «Подписанный» — строкой той же группы, что покупатель и ДКП, а не второй плашкой.
        $signedRow = $withSigned ? \Illuminate\Support\Facades\Blade::render('<x-deal.signed-contract :deal="$deal" :base="$base" bare label="Подписанный"/>', ['deal' => $deal, 'base' => '/deals/'.$deal->id]) : '';
    @endphp
    @if ($withBuyer)
        @if (! $chosen && $deal->isLive())<div class="mt-3"><x-deal.buyer-kind :deal="$deal"/></div>@endif
        <div class="mt-2">@include('cabinet.deals.contract', ['part' => $deal->hasContract() ? 'all' : 'buyer', 'embedded' => true, 'after' => $signedRow, 'labels' => true])</div>
    @elseif ($signedRow !== '')
        <div class="list mt-2">{!! $signedRow !!}</div>
    @endif
</section>
