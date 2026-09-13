{{-- Предложение менеджера одним чипом-кнопкой: аватар, имя, цена.
     Нажатие — шторка «Выбрать предложение?» (кто, сколько, за что; комментарий цитатой); у выбранного — «Отменить выбор».
     highlight — id менеджера, чьи чипы в режиме «по менеджерам» остаются яркими, остальные приглушены. --}}
@props(['offer', 'car', 'highlight' => null])
@php
    use App\Purchases\OfferState;
    $chosen = $offer->state === OfferState::Chosen;
    $sum = number_format($offer->amount, 0, '', ' ').' ₽';
    $who = $offer->user->shortName();
@endphp
<form method="post" action="/zakupki/ceny/{{ $offer->id }}/{{ $chosen ? 'otmenit' : 'vybrat' }}" class="contents"
    data-turbo-confirm="{{ $chosen ? 'Отменить выбор?' : 'Выбрать предложение?' }}"
    data-turbo-confirm-text="{{ $offer->user->name }}, {{ $sum }} за {{ $car->titleWithYear() }}{{ $chosen ? '. Остальные цены по машине снова будут ждать' : '' }}"
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
