{{-- Маршрут продажи на карточке оффера: путь по блокам, как таймлайн дела ТС (x-route.path). `$tail` — шаги после маршрута
     в пути продажи (гаражная машина). Вывоз с 17.10.2026 — не маршрут, а задача логистики: дорожка `x-logistics.lane`.
     Выбора этапа из списка нет: вернуть назад можно только на пройденный шаг. --}}
@php
    use App\Workflow\Track;
    $position = $offer->position(Track::Sale);
@endphp
@if ($position)
    <x-ui.card :title="Track::Sale->label()" class="{{ $cardClass ?? 'order-1' }}" id="sale">
        <x-route.path :offer="$offer" :position="$position">{!! $tail ?? '' !!}</x-route.path>
        @if ($errors->has('exit'))<p class="field-error mt-2">{{ $errors->first('exit') }}</p>@endif
    </x-ui.card>
@endif
