{{-- Чем ответить на дело менеджера (`Offers\ManagerAsk`) вне страницы сделки: в чате под сообщением площадки (`keys`), на
     карточке полки «Ждут вас» (`card`), в строке «Сделок» (`row`), в вопросе при входе (`sheet`). Шаг «просто нажать» —
     кнопки исходов той же формой, что на шаге (`/deals/{id}/reply` с номером просьбы: устаревшая — «Ответ уже не нужен»),
     с теми же переспросами; остальное — ссылкой на шаг сделки. Ответ возвращает туда, откуда нажали (`back`). На шаге
     получения ещё «Попросить вывоз…» — шторкой с тем, что будет. --}}
@props(['ask', 'variant' => 'card', 'back' => null])
@php
    use App\Users\Wording;
    $deal = $ask->deal;
    $back ??= request()->getRequestUri();
    $size = in_array($variant, ['card', 'row'], true) ? 's' : null;
    $pickup = ! in_array($variant, ['row', 'card'], true) && $ask->canAskPickup();
@endphp
<div {{ $attributes->class(['ask-action', 'ask-action--'.$variant]) }} @if ($pickup) data-controller="sheet" @endif>
    @if ($ask->taps())
        <form method="post" action="/deals/{{ $deal->id }}/reply" class="ask-action-form">
            @csrf
            <input type="hidden" name="requirement" value="{{ $ask->requirement->id }}">
            <input type="hidden" name="back" value="{{ $back }}">
            @foreach (in_array($variant, ['row', 'card'], true) ? $ask->moves->take(1) : $ask->moves as $exit)
                <x-ui.button name="exit" :value="$exit->id" :size="$size" :variant="$loop->first ? 'primary' : 'secondary'" :block="$variant !== 'row'"
                    :data-turbo-confirm="Wording::mine($exit->confirm) ?: ($exit->takes() ? 'Берёте в работу? После этого отказаться нельзя' : null)">{{ Wording::mine($exit->label) }}</x-ui.button>
            @endforeach
            {{-- В строке и в плитке «Ждут вас» — одна кнопка: плитки одной высоты, кнопки в ряд (10.11.2026, владелец: «всё скачет»);
                 «Передумал» и «Попросить вывоз…» — в чате, в сделке и в вопросе при входе. --}}
            @if (! in_array($variant, ['row', 'card'], true))
                @foreach ($ask->retreats as $exit)
                    <button name="exit" value="{{ $exit->id }}" class="{{ $variant === 'keys' ? 'btn btn-quiet btn-block' : 'retreat-link' }}" data-turbo-confirm="{{ Wording::mine($exit->confirm) ?: 'Передумали? Сделка отменится' }}">{{ Wording::mine($exit->label) }}</button>
                @endforeach
            @endif
        </form>
    @else
        <x-ui.button :href="$ask->href()" :size="$size" :block="$variant !== 'row'">{{ $ask->link }}</x-ui.button>
    @endif
    @if ($pickup && ! in_array($variant, ['row', 'card'], true))
        <button type="button" class="{{ $variant === 'keys' ? 'btn btn-quiet btn-block' : 'retreat-link' }}" data-action="sheet#open">Попросить вывоз…</button>
        <x-deal.pickup-ask-sheet :deal="$deal" :back="$back"/>
    @endif
</div>
