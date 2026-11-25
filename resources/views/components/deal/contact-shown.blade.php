{{-- Видит ли менеджер сделки контакт владельца — там, где контакт написан (15.11.2026, владелец: «админы постоянно спрашивают, как
     менеджеру эти контакты показать: они написаны, а менеджер их не видит, у него просто „заберите у владельца“»; «Что
     показываем» в плашке менеджера не находили). Менеджер видит только показанное (`Offers\Disclosure`, правило 03.11.2026), поэтому
     здесь — состояние и та же шторка «Показать менеджеру» (`admin.deals.reply-review-sheet`) с отмеченными именем, телефоном и
     адресом. Видит — когда показан нынешний телефон (без телефона — имя). `as`: `block` — строкой под контактом в «Вывозе»
     (`x-deal.handover`), `task` — строкой дела в текущем шаге (`x-route.path`), только пока не видит. Только админу CRM. --}}
@props(['deal', 'as' => 'block'])
@php
    use App\Offers\Disclosures;
    use App\Support\Phone;
    $offer = $deal->offer;
    $phone = Phone::normalize($offer->insured_phone);
    $has = $offer->insured_name || $offer->insured_phone;
    $live = $has && $deal->buyer_id && $deal->isActive() && ! $deal->isGarage() && auth()->user()?->canEditDeals();
    $shownAt = null;
    if ($live) {
        foreach (Disclosures::shown($deal)->sortByDesc('id') as $d) {
            $hit = $phone
                ? collect($d->values('phone'))->contains(fn ($v) => Phone::normalize($v) === $phone)
                : collect($d->values('name'))->contains(fn ($v) => mb_strtolower(trim($v)) === mb_strtolower(trim((string) $offer->insured_name)));
            if ($hit) { $shownAt = $d->created_at; break; }
        }
    }
@endphp
@if ($live && ! ($as === 'task' && $shownAt))
    @if ($shownAt)
        <p {{ $attributes->class('text-sm text-ink-muted') }}>Менеджер видит контакт с <span class="nums">{{ \App\Support\Ago::day($shownAt) }}</span></p>
    @else
        {{-- Пункты и шторка — только когда «Показать менеджеру» правда есть: разбор документов машины не бесплатный. --}}
        @php
            $id = 'disclose-contact-'.$as.'-'.$deal->id;
            $proposals = Disclosures::proposals($deal, collect());
            $picked = array_values(array_filter(array_keys($proposals), fn ($k) => preg_match('/^(name|phone|address):/', $k)));
        @endphp
        <div class="contents" data-controller="sheet">
            @if ($as === 'task')
                <x-route.task-row icon="eye" urgent aria-controls="{{ $id }}">Показать контакт менеджеру</x-route.task-row>
            @else
                {{-- Делом одной строкой, как в шаге (15.11.2026, владелец: рыжая надпись и большая кнопка — «непонятно, что хотят»). --}}
                <div {{ $attributes }}><x-route.task-row icon="eye" urgent aria-controls="{{ $id }}">Показать контакт менеджеру</x-route.task-row></div>
            @endif
            @include('admin.deals.reply-review-sheet', ['id' => $id, 'deal' => $deal, 'letters' => collect(), 'proposals' => $proposals, 'step' => null, 'picked' => $picked, 'card' => true])
        </div>
    @endif
@endif
