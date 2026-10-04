{{-- Получение автомобиля (`Offers\Handover`): где стоит, кто забирает, с кем говорить, адрес, когда. Строками `.list`:
     телефон звонит, адрес открывает карту. Отдельной карточкой под шагом — или внутри шага, когда его просьба и есть
     «Заберите автомобиль» ($embedded): тогда «Автомобиль забрал» — кнопка самой просьбы. «Забрал» здесь — только у
     вывоза без шага продажи (Альфа). --}}
@php
    /** @var \App\Offers\Handover $handover */
    $who = $handover->withBuyer ? null : ($handover->buyerPicks ? 'Забираете вы' : 'Заберём сами');
@endphp
@if ($handover->shows())
    <div @class(['box' => ! $embedded, 'mt-5' => $embedded])>
        @unless ($embedded)
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2>Получение автомобиля</h2>
                @if ($who)<p class="text-sm text-ink-muted">{{ $who }}</p>@endif
            </div>
        @endunless
        <div @class(['list', 'mt-4' => ! $embedded])>
            @if ($handover->where)
                <div class="row items-center gap-3">
                    <x-ui.row-icon name="car" size="s" :tone="$handover->withBuyer ? 'open' : 'plain'"/>
                    <span class="min-w-0 flex-1">{{ $handover->where }}</span>
                </div>
            @endif
            @if ($handover->name || $handover->phone)
                <{{ $handover->phone ? 'a' : 'div' }} @if ($handover->phone) href="tel:+{{ $handover->phone }}" @endif class="row items-center gap-3">
                    <x-ui.row-icon name="phone" size="s" :tone="$handover->phone ? 'accent' : 'plain'"/>
                    <span class="min-w-0 flex-1">
                        @if ($handover->name)<span class="block break-words">{{ $handover->name }}</span>@endif
                        @if ($handover->phone)<span @class(['nums block', 'text-sm text-ink-muted' => $handover->name])>{{ $handover->phoneFormatted() }}</span>@endif
                    </span>
                    @if ($handover->phone)<x-ui.chevron/>@endif
                </{{ $handover->phone ? 'a' : 'div' }}>
            @endif
            @if ($handover->address)
                <a href="{{ $handover->mapUrl }}" target="_blank" rel="noopener" class="row items-center gap-3">
                    <x-ui.row-icon name="map-pin" size="s"/>
                    <span class="min-w-0 flex-1 whitespace-pre-line break-words">{{ $handover->address }}</span>
                    <x-ui.chevron/>
                </a>
            @endif
            @if ($handover->dateLabel())
                <div class="row items-center gap-3">
                    <x-ui.row-icon name="clock" size="s"/>
                    <span class="nums min-w-0 flex-1">{{ $handover->dateLabel() }}</span>
                </div>
            @endif
        </div>
        @if ($handover->servicePick)
            <form method="post" action="/deals/{{ $deal->id }}/picked" class="mt-4" data-turbo-confirm="Забрали автомобиль?" data-turbo-confirm-label="Забрал">
                @csrf<x-ui.button block>Автомобиль забрал</x-ui.button>
            </form>
        @endif
    </div>
@endif
