{{-- Пункты меню действий машины (`CarView::actions`) — одни в окне («⋯» в полосе, `mode=menu`: popover строками) и на
     странице («···» плашки действий, `mode=sheet`: кнопками шторки). Группы — как в iOS, опасное — красным последним. --}}
@php $menu = ($mode ?? 'menu') === 'menu'; $close = $menu ? 'menu#close' : 'sheet#close'; @endphp
@foreach ($groups as $group)
    <div @class(['menu-group' => $menu, 'flex flex-col gap-2' => ! $menu])>
        @foreach ($group as $item)
            @switch($item['kind'])
                @case('view')
                    @if ($item['view'] === 'admin.car.publish-items')
                        @if ($menu)
                            <div class="menu-head">Выставить в продажу</div>
                            @foreach (\App\Offers\Slots::choices() as $c)
                                <form method="post" action="/offers/{{ $n }}/state" data-price-guard="publish" data-price-guard-offer="{{ $n }}">
                                    @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ $c['when'] }}">
                                    <button class="menu-item" role="menuitem" data-action="{{ $close }}"><x-ui.icon :name="$c['at'] ? 'clock' : 'send'" class="size-5 text-ink-muted"/>{{ $c['at'] ? str_replace(', ', ' в ', $c['title']) : 'Сейчас' }}</button>
                                </form>
                            @endforeach
                        @else
                            <x-offer.publish-items :offer="$offer" button/>
                        @endif
                    @elseif ($item['view'] === 'admin.car.exclusive-off')
                        <x-offer.exclusive-off :offer="$offer" :button="! $menu"/>
                    @elseif ($item['view'] === 'admin.car.auction-items')
                        @include('admin.offers.auction-items', ['offer' => $offer, 'menu' => $menu])
                    @endif
                    @break
                @case('emit')
                    @if ($menu)
                        <button type="button" @class(['menu-item', 'text-danger' => $item['danger']]) role="menuitem" data-controller="emit" data-action="emit#send {{ $close }}" data-emit-event-param="{{ $item['event'] }}:open">@if ($item['fire'] ?? false)<x-ui.icon name="fire" fill class="size-5 text-fire"/>@else<x-ui.icon :name="$item['icon']" @class(['size-5', 'text-ink-muted' => ! $item['danger']])/>@endif{{ $item['label'] }}</button>
                    @else
                        <x-ui.button type="button" block :variant="$item['danger'] ? 'danger' : 'secondary'" :class="($item['fire'] ?? false) ? 'btn-fire' : ''" data-controller="emit" data-action="emit#send {{ $close }}" data-emit-event-param="{{ $item['event'] }}:open">{{ $item['label'] }}</x-ui.button>
                    @endif
                    @break
                @case('post')
                    <form method="post" action="{{ $item['action'] }}"
                        @if ($item['guard']) data-price-guard="publish" data-price-guard-offer="{{ $n }}" @endif
                        @if ($item['confirm']) data-turbo-confirm="{{ $item['confirm'] }}" @if ($item['text']) data-turbo-confirm-text="{{ $item['text'] }}" @endif @if ($item['confirmLabel']) data-turbo-confirm-label="{{ $item['confirmLabel'] }}" @endif @endif
                        @if ($item['top'] ?? false) data-turbo-frame="_top" @endif>
                        @csrf @if ($item['method']) @method($item['method']) @endif
                        @foreach ($item['fields'] as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                        @if ($menu)
                            <button @class(['menu-item', 'text-danger' => $item['danger']]) role="menuitem" data-action="{{ $close }}"><x-ui.icon :name="$item['icon']" @class(['size-5', 'text-ink-muted' => ! $item['danger']])/>{{ $item['label'] }}</button>
                        @else
                            <x-ui.button block :variant="$item['danger'] ? 'danger' : 'secondary'">{{ $item['label'] }}</x-ui.button>
                        @endif
                    </form>
                    @break
            @endswitch
        @endforeach
    </div>
@endforeach
