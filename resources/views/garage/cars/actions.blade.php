{{-- Что сейчас можно сделать с машиной (`GarageView`): главные кнопки и «⋯» строками. bar — плашка действий страницы
     на сайте; иначе ряд маленьких кнопок в карточке CRM. Шторки — `garage.cars.sheets`, их зовёт событие «имя:open». --}}
@php $bar ??= false; $size = $bar ? 'min-w-0 flex-1' : 'btn-s'; @endphp
@if ($buttons || $more)
    <div @class(['contents' => $bar, 'flex flex-wrap items-center gap-2' => ! $bar])>
        @foreach ($buttons as $i => $b)
            @if ($b[0] === 'post')
                <form method="post" action="{{ $b[1] }}" class="contents">@csrf<button type="submit" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} {{ $size }}">{{ $b[2] }}</button></form>
            @else
                <button type="button" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} {{ $size }}" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $b[1] }}:open">
                    @if ($b[1] === 'cost-new')<x-ui.icon name="plus" class="size-5"/>@endif{{ $b[2] }}
                </button>
            @endif
        @endforeach
        @if ($more)
            <span data-controller="sheet" class="contents">
                <button type="button" class="btn btn-quiet btn-round {{ $bar ? 'btn-lg' : 'btn-s' }} {{ $buttons ? '' : 'ml-auto' }}" data-action="sheet#open" aria-label="Ещё"><x-ui.icon name="more" class="{{ $bar ? 'size-6' : 'size-5' }}"/></button>
                <x-ui.sheet id="car-more-{{ $n }}{{ $menuKey ?? '' }}" title="{{ $offer->titleWithYear() }}">
                    {{-- Меню строками, как «···» в приложении: редкое действие — словом, опасное — красным, не стопка кнопок. --}}
                    <div class="list">
                        @foreach ($more as $item)
                            @if ($item[0] === 'emit')
                                <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="{{ $item[1] }}:open">{{ $item[2] }}</button>
                            @else
                                <form method="post" action="{{ $item[2] }}" @if ($item[4]) data-turbo-confirm="{{ $item[4] }}" @endif class="contents">@csrf @method($item[1])<button type="submit" class="row w-full text-left {{ $item[1] === 'DELETE' ? 'text-danger' : '' }}">{{ $item[3] }}</button></form>
                            @endif
                        @endforeach
                    </div>
                </x-ui.sheet>
            </span>
        @endif
    </div>
@endif
