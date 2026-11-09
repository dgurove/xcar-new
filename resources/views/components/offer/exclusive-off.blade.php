{{-- «Выставить всем…» / «Открыть всем сейчас…» (09.11.2026, владелец: после оценки публикация всегда эксклюзивная, «для всех —
     супер редкое исключение, в доп. меню и с предупреждением»): пункт «Действий» и кнопка «···» редактора. Черновик — та же
     публикация сейчас с `all=1`, в продаже — `exclusive-off`; спрашивает всегда, красным, называя оценщика (`Exclusive`). --}}
@props(['offer', 'button' => false])
@php
    use App\Offers\{Exclusive, OfferState};
    $draft = in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true);
    $due = match (true) {
        $draft => $offer->asking_price && ! $offer->isScheduled() && ! $offer->onAuction() ? Exclusive::due($offer) : collect(),
        $offer->state === OfferState::Open => Exclusive::running($offer),
        default => collect(),
    };
@endphp
@if ($due->isNotEmpty())
    <form method="post" action="/offers/{{ $offer->number }}/{{ $draft ? 'state' : 'exclusive-off' }}"
          @if ($draft) data-price-guard="publish" data-price-guard-offer="{{ $offer->number }}" @endif
          data-turbo-confirm="Оценивал {{ Exclusive::who($due) }}. {{ $draft ? 'Выставить всем' : 'Открыть всем сейчас' }}?"
          data-turbo-confirm-text="{{ Exclusive::warning($due) }}" data-turbo-confirm-label="Да, всем">
        @csrf
        @if ($draft)<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ \App\Offers\Slots::NOW }}"><input type="hidden" name="all" value="1">@endif
        @if ($button)
            <x-ui.button block variant="secondary" class="text-danger">{{ $draft ? 'Выставить всем…' : 'Открыть всем сейчас…' }}</x-ui.button>
        @else
            <button class="menu-item text-danger" role="menuitem" data-action="menu#close"><x-ui.icon name="users" class="size-5"/>{{ $draft ? 'Выставить всем…' : 'Открыть всем сейчас…' }}</button>
        @endif
    </form>
@endif
