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
    $live = $has && $deal->buyer_id && $deal->isActive() && ! $deal->isGarage() && auth()->user()?->canManageCrm();
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
        <p {{ $attributes->class('text-sm text-open') }}>Менеджер видит с <span class="nums">{{ \App\Support\Ago::day($shownAt) }}</span></p>
    @else
        {{-- Пункты и шторка — только когда «Показать менеджеру» правда есть: разбор документов машины не бесплатный. --}}
        @php
            $id = 'disclose-contact-'.$as.'-'.$deal->id;
            $proposals = Disclosures::proposals($deal, collect());
            $picked = array_values(array_filter(array_keys($proposals), fn ($k) => preg_match('/^(name|phone|address):/', $k)));
        @endphp
        <div class="contents" data-controller="sheet">
            @if ($as === 'task')
                <x-route.task-row icon="user" urgent aria-controls="{{ $id }}">Менеджер не видит контакт владельца</x-route.task-row>
            @else
                <div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-2') }}>
                    <span class="min-w-0 flex-1 text-sm text-urgent">Менеджер не видит контакт</span>
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open" aria-controls="{{ $id }}">Показать менеджеру</button>
                </div>
            @endif
            @include('admin.deals.reply-review-sheet', ['id' => $id, 'deal' => $deal, 'letters' => collect(), 'proposals' => $proposals, 'step' => null, 'picked' => $picked])
        </div>
    @endif
@endif
