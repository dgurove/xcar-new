{{-- Что сейчас можно сделать с машиной (`GarageView`): главные кнопки и «⋯» строками. bar — плашка действий страницы
     на сайте (большая круглая «⋯» со шторкой строками); иначе ряд маленьких кнопок в карточке CRM и «⋯» пилюлей со шторкой
     действий (`x-ui.more`). Шторки — `garage.cars.sheets`, их зовёт событие «имя:open». --}}
@php $bar ??= false; $size = $bar ? 'min-w-0 flex-1' : 'btn-s'; @endphp
@if ($buttons || $more)
    <div @class(['contents' => $bar, 'flex flex-wrap items-center gap-2' => ! $bar])>
        @foreach ($buttons as $i => $b)
            @if ($b[0] === 'post')
                <form method="post" action="{{ $b[1] }}" class="contents" @if ($b[3] ?? null) data-turbo-confirm="{{ $b[3] }}" @endif>@csrf<button type="submit" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} {{ $size }}">{{ $b[2] }}</button></form>
            @elseif ($b[0] === 'link')
                <a href="{{ $b[1] }}" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} {{ $size }}">{{ $b[2] }}</a>
            @else
                <button type="button" class="btn {{ $i ? 'btn-quiet' : 'btn-accent' }} {{ $size }}" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $b[1] }}:open">
                    @if ($b[1] === 'cost-new')<x-ui.icon name="plus" class="size-5"/>@endif{{ $b[2] }}
                </button>
            @endif
        @endforeach
        @if ($more && ! $bar)
            {{-- В карточке CRM — общей пилюлей «⋯» со шторкой действий (`x-ui.more`, 12.11.2026): опасное (DELETE) — последней группой. --}}
            @php [$calm, $danger] = collect($more)->partition(fn ($item) => $item[0] === 'emit' || $item[1] !== 'DELETE')->all(); @endphp
            <x-ui.more :id="'car-more-'.$n.($menuKey ?? '')" :class="$buttons ? '' : 'ml-auto'">
                @foreach (array_filter([$calm, $danger], fn ($g) => $g->isNotEmpty()) as $group)
                    <div class="menu-group">
                        @foreach ($group as $item)
                            @if ($item[0] === 'emit')
                                <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $item[1] }}:open">{{ $item[2] }}</button>
                            @else
                                <form method="post" action="{{ $item[2] }}" @if ($item[4]) data-turbo-confirm="{{ $item[4] }}" @endif class="contents">@csrf @method($item[1])<button type="submit" @class(['menu-item w-full', 'text-danger' => $item[1] === 'DELETE']) role="menuitem">{{ $item[3] }}</button></form>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </x-ui.more>
        @elseif ($more)
            {{-- Плашка действий страницы на сайте — большой круглой кнопкой рядом с главной, шторка строками. --}}
            <span data-controller="sheet" class="contents">
                <button type="button" class="btn btn-quiet btn-round btn-lg {{ $buttons ? '' : 'ml-auto' }}" data-action="sheet#open" aria-label="Ещё"><x-ui.icon name="more" class="size-6"/></button>
                {{-- Та же шторка действий, что у «⋯» (`x-ui.more`, 12.11.2026). --}}
                <x-ui.sheet id="car-more-{{ $n }}{{ $menuKey ?? '' }}" anchor bare class="sheet-actions">
                    <div class="sheet-actions-list" data-action="click->sheet#item">
                        @foreach ($more as $item)
                            @if ($item[0] === 'emit')
                                <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $item[1] }}:open">{{ $item[2] }}</button>
                            @else
                                <form method="post" action="{{ $item[2] }}" @if ($item[4]) data-turbo-confirm="{{ $item[4] }}" @endif class="contents">@csrf @method($item[1])<button type="submit" @class(['menu-item w-full', 'text-danger' => $item[1] === 'DELETE']) role="menuitem">{{ $item[3] }}</button></form>
                            @endif
                        @endforeach
                    </div>
                    <button type="button" class="sheet-actions-cancel" data-action="sheet#close">Отмена</button>
                </x-ui.sheet>
            </span>
        @endif
    </div>
@endif
