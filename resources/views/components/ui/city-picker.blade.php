{{-- Города менеджера: выбранные — чипами с крестиком, ниже поиск по справочнику (combobox), выбранный город встаёт чипом.
     Поля `cities[]` лежат в чипах; `city_add` — служебное поле поиска, сервер его не читает. --}}
@props(['selected' => collect(), 'label' => 'Города, где вы работаете', 'url' => '/cities'])
<div class="flex flex-col gap-2" data-controller="city-picker" data-action="change->city-picker#add">
    <div class="flex flex-wrap gap-1.5 empty:hidden" data-city-picker-target="chips">
        @foreach ($selected as $city)
            <span class="chip" data-id="{{ $city->id }}"><input type="hidden" name="cities[]" value="{{ $city->id }}">{{ $city->name }}<button type="button" class="-mr-1 ml-1 inline-flex size-5 items-center justify-center text-ink-muted" data-action="city-picker#remove" aria-label="Убрать {{ $city->name }}"><x-ui.icon name="x" class="size-3.5"/></button></span>
        @endforeach
    </div>
    <x-ui.combobox name="city_add" :label="$label" :url="$url" placeholder="Начните вводить город"/>
    @if ($errors->has('cities') || $errors->has('cities.*'))<p class="field-error">{{ $errors->first('cities') ?: $errors->first('cities.*') }}</p>@endif
</div>
