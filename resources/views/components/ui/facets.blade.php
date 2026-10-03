{{-- Ряд чипов фильтра (App\Support\Facets): на телефоне своей строкой под кнопками тулбара, листается вбок. Чип со
     шторкой — x-ui.facet-chip, переключатель («Непрочитанные», «Мои») — пилюля-ссылка. Выбрано хоть что-то —
     последним «Сбросить». --}}
@props(['facets', 'name' => 'list'])
@php $chips = $facets->chips(); @endphp
@if ($chips->isNotEmpty())
    <div class="toolbar-chips">
        <div class="flex flex-nowrap items-center gap-2">
            @foreach ($chips as $chip)
                @if ($chip->facet->toggle)
                    @php $on = $chip->selected !== []; @endphp
                    <a href="{{ $facets->url([$chip->facet->key => $on ? '' : '1']) }}" class="pill {{ $on ? '' : ($chip->facet->tone ?? '') }}" data-turbo-action="replace" data-turbo-prefetch="false" @if ($on) aria-current="true" @endif>{{ $chip->label }}@if ($chip->count) <span class="nums opacity-70">{{ $chip->count }}</span>@endif</a>
                @else
                    <x-ui.facet-chip :chip="$chip" :facets="$facets" :id="'facet-'.$name.'-'.$chip->facet->key"/>
                @endif
            @endforeach
            @if ($facets->active())
                <a href="{{ $facets->resetUrl() }}" class="pill facet-reset" data-turbo-action="replace" data-turbo-prefetch="false">Сбросить</a>
            @endif
        </div>
    </div>
@endif
