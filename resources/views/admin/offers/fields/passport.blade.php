{{-- Паспорт ТС у опубликованной машины (06.10.2026, владелец: поля уже заполнены, правят редко): чья и номер убытка,
     ниже факты, как на витрине (`x-offer.facts`, VIN целиком); название — в шапке страницы, здесь не повторяем. Правки
     внутри нет — «Развернуть» в заголовке карточки открывает полную форму. Пустые поля не показываются. --}}
<div class="flex flex-col gap-4">
    @if ($offer->vendor || $offer->claim_ref)
        <div class="flex flex-wrap gap-x-3 font-medium">
            @if ($offer->vendor)<span>{{ $offer->vendor->name }}</span>@endif
            @if ($offer->claim_ref)<span class="nums text-ink-muted">{{ $offer->claim_ref }}</span>@endif
        </div>
    @endif
    <x-offer.facts :offer="$offer" full bare/>
    @if ($offer->description)<p class="whitespace-pre-line text-ink-muted">{{ $offer->description }}</p>@endif
</div>
