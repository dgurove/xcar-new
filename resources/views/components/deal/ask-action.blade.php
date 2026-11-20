{{-- Чем ответить на дело менеджера (`Offers\ManagerAsk`) вне страницы сделки: в чате под сообщением площадки (`keys`), на
     карточке полки «Ждут вас» (`card`), в строке «Сделок» (`row`), в вопросе при входе (`sheet`). Шаг «просто нажать» —
     кнопки исходов той же формой, что на шаге (`/deals/{id}/reply` с номером просьбы: устаревшая — «Ответ уже не нужен»),
     с теми же переспросами; остальное — ссылкой на шаг сделки. Ответ возвращает туда, откуда нажали (`back`). На шаге
     получения ещё «Попросить вывоз…» — шторкой с тем, что будет. --}}
@props(['ask', 'variant' => 'card', 'back' => null])
@php
    use App\Offers\Warnings;
    use App\Users\Wording;
    $deal = $ask->deal;
    $back ??= request()->getRequestUri();
    $size = in_array($variant, ['card', 'row'], true) ? 's' : null;
    // Вопрос при входе (`sheet`) — только ответ и «Ещё нет» (18.11.2026, владелец: «убери „я передумал“ — чтобы отменить, пусть
    // проваливаются в сделку; чисто подтвердить или ещё нет»): ни отказа, ни «Попросить вывоз…» рядом — отказ только третьей
    // кнопкой окна «Беру в работу» (19.11.2026, начальник: окно одно везде).
    $plain = in_array($variant, ['row', 'card', 'sheet'], true);
    $pickup = ! $plain && $ask->canAskPickup();
    // Одна страница бывает с несколькими видами одного вопроса (чат и полка) — id формы и окна свои у вида и места.
    $form = 'ask-'.($ask->requirement?->id).'-'.$variant.'-'.substr(md5((string) $back), 0, 6);
    $shown = $ask->taps() ? (in_array($variant, ['row', 'card'], true) ? $ask->moves->take(1) : $ask->moves) : collect();
@endphp
<div {{ $attributes->class(['ask-action', 'ask-action--'.$variant]) }} @if ($pickup) data-controller="sheet" @endif>
    @if ($ask->taps())
        <form method="post" action="/deals/{{ $deal->id }}/reply" id="{{ $form }}" class="ask-action-form">
            @csrf
            <input type="hidden" name="requirement" value="{{ $ask->requirement->id }}">
            <input type="hidden" name="back" value="{{ $back }}">
            @foreach ($shown as $exit)
                @if (Warnings::takes($exit))
                    {{-- «Беру в работу» — окно с тремя кнопками (`x-deal.take-sheet`) во всех видах; в вопросе при входе (`sheet`) — без «Отказываюсь»
                         (19.11.2026, владелец: отказ там не показывать, он — на странице сделки). --}}
                    <x-ui.button type="button" :size="$size" :variant="$loop->first ? 'primary' : 'secondary'" :block="$variant !== 'row'"
                        data-controller="emit" data-action="emit#send" :data-emit-event-param="'take:'.$form.'-'.$exit->id">{{ Wording::mine($exit->label) }}</x-ui.button>
                @else
                    <x-ui.button name="exit" :value="$exit->id" :size="$size" :variant="$loop->first ? 'primary' : 'secondary'" :block="$variant !== 'row'"
                        :data-turbo-confirm="Wording::mine($exit->confirm) ?: null">{{ Wording::mine($exit->label) }}</x-ui.button>
                @endif
            @endforeach
            {{-- В строке и в плитке «Ждут вас» — одна кнопка: плитки одной высоты, кнопки в ряд (10.11.2026, владелец: «всё скачет»);
                 «Отказываюсь» и «Попросить вывоз…» — в чате, в сделке и в окне «Беру в работу». --}}
        </form>
        @foreach ($shown->filter(fn ($e) => Warnings::takes($e)) as $exit)
            <x-deal.take-sheet :id="$form.'-'.$exit->id" :form="$form" :take="['exit', $exit->id]" :ok="$exit->takes() ? 'Да, беру в работу' : 'Да, берём'"
                :drop="$variant !== 'sheet' && ($r = $ask->retreats->first()) ? ['exit', $r->id] : null" :deal="$deal" :ask="$ask->requirement" :back="$back"/>
        @endforeach
        {{-- «Отказываюсь» и «Напомнить через…» — двумя кнопками в ряд (19.11.2026, как на шаге сделки, `cabinet.deals.exits`): ряд за
             формой ответа — у шторки напоминания свои формы, «Отказываюсь» шлёт ответ атрибутом `form`. --}}
        @if (! $plain && $ask->retreats->isNotEmpty())
            <div class="mt-2 flex gap-2">
                @foreach ($ask->retreats as $exit)
                    <button form="{{ $form }}" name="exit" value="{{ $exit->id }}" class="btn btn-quiet shrink-0 px-5" {{ Warnings::html(Warnings::DEAL_OFF, 'Да, отказываюсь', danger: true) }}><span class="truncate">{{ Wording::mine($exit->label) }}</span></button>
                @endforeach
                <x-deal.remind :deal="$deal" :ask="$ask->requirement" :back="$back"/>
            </div>
        @endif
    @elseif (\App\Support\Surface::current() === \App\Support\Surface::Chat)
        {{-- В XCAR Chat шаг сделки — шторкой поверх чата (`x-ui.deal-sheet`). --}}
        <a href="{{ \App\Chats\Chat::carHref($deal->offer, auth()->user(), $ask->href()) }}" @class(['btn btn-accent', 'btn-s' => in_array($size, ['s', 'sm'], true), 'btn-block' => $variant !== 'row']) {!! \App\Chats\Chat::sheetAttrs($deal->offer) !!}>{{ $ask->link }}</a>
    @else
        <x-ui.button :href="$ask->href()" :size="$size" :block="$variant !== 'row'">{{ $ask->link }}</x-ui.button>
    @endif
    @if ($pickup)
        <button type="button" class="{{ $variant === 'keys' ? 'btn btn-quiet btn-block' : 'retreat-link' }}" data-action="sheet#open">Попросить вывоз…</button>
        <x-deal.pickup-ask-sheet :deal="$deal" :back="$back"/>
    @endif
</div>
