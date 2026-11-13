{{-- Покупатель на странице сделки (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу»): с согласия — у ДКП,
     ПРАЙМ и «страховой напрямую» (`Deal::needsBuyer`). Попросили (`buyer_asked_at`) — заголовком дело, без срока; покупателя ещё
     нет — сначала плитки «Паспорт» / «Карточка фирмы», ниже выбор из своих (`cabinet.deals.contract`). Прислал фото — что получили и «внесём
     сами», у ИП без паспорта — «Ещё нужен паспорт ИП». Окно «Кто покупатель?» сразу после «Беру в работу» — `x-deal.buyer-ask-sheet`. Белой карточкой (11.11.2026, владелец:
     «висит в воздухе — тут как раз нужен блок»): плитки и выбор внутри неё серыми. --}}
@php
    $asked = $deal->buyer_asked_at;
    $contract = \App\Offers\DealContract::for($deal);
    $chosen = (bool) $contract->buyer_user_id;
    // Прислал фото (13.11.2026): что получили — словами; у ИП второй части нет — «Ещё нужен паспорт ИП» плиткой.
    $party = $deal->buyer_scans_at ? $contract->buyer?->party : null;
    $ip = $party?->kind === \App\Billing\PartyKind::Entrepreneur;
    $hasPassport = (bool) $party?->passportScan('main');
    $hasCard = (bool) $party?->getMedia('card')->isNotEmpty();
    $missing = $ip && ! $hasPassport ? 'passport' : ($ip && ! $hasCard && ! $party->readyForContract() ? 'card' : null);
    // Внесли, а не хватает паспорта ИП (`Deal::buyerNeed`, 13.11.2026) — заголовком дело и плитка паспорта.
    $need = ! $deal->buyer_scans_at && $chosen && $deal->isLive() ? $deal->buyerNeed() : null;
    $needIp = $need && $need['kind'] === 'passport' && $contract->buyer?->party?->kind === \App\Billing\PartyKind::Entrepreneur;
    $got = match (true) {
        $ip && $hasPassport && $hasCard => 'Паспорт и реквизиты ИП получили',
        $ip && $hasPassport => 'Паспорт ИП получили',
        $ip => 'Реквизиты ИП получили',
        $hasPassport => 'Паспорт получили',
        default => 'Реквизиты получили',
    };
@endphp
<section class="box min-w-0">
    <h2>{{ $need ? $need['title'] : ($asked && ! $chosen ? 'Укажите покупателя' : ($deal->isGarage() ? 'Договор купли-продажи' : 'Покупатель')) }}</h2>
    @if ($deal->buyer_scans_at)
        <div class="list mt-3"><div class="row"><x-ui.row-icon name="check" size="s" tone="open"/><span class="min-w-0 flex-1">{{ $got }}<span class="row-sub">внесём сами</span></span></div></div>
        @if ($missing)<div class="mt-2"><x-deal.buyer-photos :deal="$deal" :only="$missing"/></div>@endif
    @else
        @if (! $chosen && $deal->isLive())<div class="mt-3"><x-deal.buyer-photos :deal="$deal"/></div>@endif
        @if ($needIp)<div class="mt-3"><x-deal.buyer-photos :deal="$deal" only="passport"/></div>@endif
        <div class="mt-2">@include('cabinet.deals.contract', ['part' => $deal->hasContract() ? 'all' : 'buyer', 'embedded' => true])</div>
    @endif
</section>
