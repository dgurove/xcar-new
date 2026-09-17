{{-- Предложение менеджера одним чипом-кнопкой: аватар, имя, цена.
     Нажатие — шторка «Выбрать предложение?» (кто, сколько, за что; комментарий цитатой); у выбранного — «Отменить выбор».
     highlight — id менеджера, чьи чипы в режиме «по менеджерам» остаются яркими, остальные приглушены. --}}
@props(['offer', 'car', 'highlight' => null])
@php
    use App\Purchases\OfferState;
    $chosen = $offer->state === OfferState::Chosen;
    $sum = \App\Support\Money::rub($offer->amount);
    $who = $offer->user->shortName();
@endphp
<form method="post" action="/purchases/prices/{{ $offer->id }}/{{ $chosen ? 'cancel' : 'choose' }}" class="contents"
    data-turbo-confirm="{{ $chosen ? 'Отменить выбор?' : 'Выбрать предложение?' }}"
    data-turbo-confirm-text="{{ $offer->user->name }}, {{ $sum }} за {{ $car->titleWithYear() }}{{ $chosen ? '. Остальные цены по этому ТС снова будут ждать' : '' }}"
    data-turbo-confirm-label="{{ $chosen ? 'Отменить выбор' : 'Выбрать' }}"
    @if ($offer->comment) data-turbo-confirm-quote="{{ $offer->comment }}" data-turbo-confirm-quote-by="{{ $offer->user->name }}" @endif>
    @csrf
    <button type="submit" {{ $attributes->merge(['class' => 'chip person offer-chip'.($chosen ? ' offer-chip-chosen' : '').($highlight && $offer->user_id !== $highlight ? ' opacity-60' : '')]) }}>
        <x-ui.avatar :user="$offer->user" :size="20"/>
        <span class="truncate">{{ $who }}</span>
        <span class="nums whitespace-nowrap">{{ $sum }}</span>
        @if ($chosen)<x-ui.icon name="check" class="!size-3.5"/>@endif
    </button>
</form>
