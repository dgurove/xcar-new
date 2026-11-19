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
    // Вопрос при входе (`sheet`) — только ответ и «Ещё нет» (18.11.2026, владелец: «убери „я передумал“ — чтобы отменить, пусть
    // проваливаются в сделку; чисто подтвердить или ещё нет»): ни отказа, ни «Попросить вывоз…» — они на странице сделки.
    $plain = in_array($variant, ['row', 'card', 'sheet'], true);
    $pickup = ! $plain && $ask->canAskPickup();
@endphp
<div {{ $attributes->class(['ask-action', 'ask-action--'.$variant]) }} @if ($pickup) data-controller="sheet" @endif>
    @if ($ask->taps())
        <form method="post" action="/deals/{{ $deal->id }}/reply" id="ask-{{ $ask->requirement->id }}-{{ $variant }}" class="ask-action-form">
            @csrf
            <input type="hidden" name="requirement" value="{{ $ask->requirement->id }}">
            <input type="hidden" name="back" value="{{ $back }}">
            @foreach (in_array($variant, ['row', 'card'], true) ? $ask->moves->take(1) : $ask->moves as $exit)
                <x-ui.button name="exit" :value="$exit->id" :size="$size" :variant="$loop->first ? 'primary' : 'secondary'" :block="$variant !== 'row'"
                    :data-turbo-confirm="\App\Offers\Warnings::takes($exit) ? \App\Offers\Warnings::TITLE : (Wording::mine($exit->confirm) ?: null)" :data-turbo-confirm-text="\App\Offers\Warnings::takes($exit) ? \App\Offers\Warnings::TAKE : null" :data-turbo-confirm-label="\App\Offers\Warnings::takes($exit) ? ($exit->takes() ? 'Да, беру в работу' : 'Да, берём') : null" :data-turbo-confirm-cancel="\App\Offers\Warnings::takes($exit) ? \App\Offers\Warnings::CANCEL : null">{{ Wording::mine($exit->label) }}</x-ui.button>
            @endforeach
            {{-- В строке и в плитке «Ждут вас» — одна кнопка: плитки одной высоты, кнопки в ряд (10.11.2026, владелец: «всё скачет»);
                 «Передумал» и «Попросить вывоз…» — в чате, в сделке и в вопросе при входе. --}}
        </form>
        {{-- «Я передумал» и «Напомнить через…» — двумя кнопками в ряд (19.11.2026, как на шаге сделки, `cabinet.deals.exits`): ряд за
             формой ответа — у шторки напоминания свои формы, «Я передумал» шлёт ответ атрибутом `form`. --}}
        @if (! $plain && $ask->retreats->isNotEmpty())
            <div class="mt-2 flex gap-2">
                @foreach ($ask->retreats as $exit)
                    <button form="ask-{{ $ask->requirement->id }}-{{ $variant }}" name="exit" value="{{ $exit->id }}" class="btn btn-quiet shrink-0 px-5" {{ \App\Offers\Warnings::html(\App\Offers\Warnings::DEAL_OFF, $exit->label === 'Я передумал' ? 'Да, передумал' : 'Да, отказываюсь', danger: true) }}><span class="truncate">{{ Wording::mine($exit->label) }}</span></button>
                @endforeach
                <x-deal.remind :deal="$deal" :ask="$ask->requirement" :back="$back"/>
            </div>
        @endif
    @else
        <x-ui.button :href="$ask->href()" :size="$size" :block="$variant !== 'row'">{{ $ask->link }}</x-ui.button>
    @endif
    @if ($pickup)
        <button type="button" class="{{ $variant === 'keys' ? 'btn btn-quiet btn-block' : 'retreat-link' }}" data-action="sheet#open">Попросить вывоз…</button>
        <x-deal.pickup-ask-sheet :deal="$deal" :back="$back"/>
    @endif
</div>
