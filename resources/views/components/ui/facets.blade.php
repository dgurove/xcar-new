{{-- Ряд чипов фильтра (App\Support\Facets): на телефоне своей строкой под кнопками тулбара, листается вбок. Чип со
     шторкой — x-ui.facet-chip, переключатель («Непрочитанные», «Мои») — пилюля-ссылка. Выбрано хоть что-то —
     последним «Сбросить» — чипы как по умолчанию; `reset=false` — экран держит его сам (предложения CRM — значком у
     названия). --}}
@props(['facets', 'name' => 'list', 'reset' => true, 'part' => null])
@php
    // Шапка вкладок колеса (`x-ui.toolbar rows`, 17.11.2026): ряд `quick` — быстрые чипы и выбранное в «Фильтре» (чипом с ×,
    // нажатие открывает окно «Фильтр», `detail#filter`), ряд `toggles` — переключатели; «Сбросить» там — у кнопки «Фильтр».
    $chips = $facets->chips();
    $chips = match ($part) {
        'quick' => $chips->reject(fn ($c) => $c->facet->toggle),
        'toggles' => $chips->filter(fn ($c) => $c->facet->toggle),
        default => $chips,
    };
@endphp
@if ($chips->isNotEmpty())
    <div class="{{ $part ? 'toolbar-row toolbar-row--'.$part : 'toolbar-chips' }}">
        <div class="flex flex-nowrap items-center gap-2">
            @foreach ($chips as $chip)
                @if ($part === 'quick' && ! $chip->on)
                    {{-- Шапка вкладок колеса: выпадающих быстрых чипов нет (17.11.2026, владелец: «не нужны, выбирать в фильтре») —
                         только выбранное, чипом с ×. --}}
                @elseif ($part === 'quick')
                    @php $f = $chip->facet; $text = $f->range || $f->dates ? $f->title.': '.mb_strtolower(mb_substr($chip->label, 0, 1)).mb_substr($chip->label, 1) : $chip->label; @endphp
                    <span class="pill facet-on" aria-current="true">
                        <a href="{{ \App\Support\Detail::url(\App\Support\Detail::FILTER) }}" class="facet-open" data-action="detail#filter" data-turbo-prefetch="false">{{ $text }}</a>
                        <a href="{{ $facets->url([$f->key => '']) }}" class="facet-x" data-turbo-action="replace" data-turbo-prefetch="false" aria-label="Снять «{{ $f->title }}»"><x-ui.icon name="x" class="size-3.5"/></a>
                    </span>
                @elseif ($chip->facet->toggle)
                    @php $on = $chip->on ?? $chip->selected !== []; @endphp
                    <a href="{{ $facets->toggleUrl($chip->facet, $on) }}" class="pill {{ $on ? '' : ($chip->facet->tone ?? '') }}" @if ($chip->facet->dot) data-tone="{{ $chip->facet->dot }}" @endif data-turbo-action="replace" data-turbo-prefetch="false" @if ($on) aria-current="true" @endif>@if ($chip->facet->dot === 'urgent')<x-ui.icon name="fire" fill class="mr-1 size-4 {{ $on ? '' : 'text-fire' }}"/>@elseif ($chip->facet->dot)<span class="card-tone card-tone--{{ $chip->facet->dot }}"></span>@endif{{ $chip->label }}@if ($chip->count) <span class="nums opacity-70">{{ $chip->count }}</span>@endif</a>
                @else
                    <x-ui.facet-chip :chip="$chip" :facets="$facets" :id="'facet-'.$name.'-'.$chip->facet->key"/>
                @endif
            @endforeach
            @if ($reset && ! $part && $facets->active())
                <a href="{{ $facets->resetUrl() }}" class="facet-reset" data-turbo-action="replace" data-turbo-prefetch="false">Сбросить</a>
            @endif
        </div>
    </div>
@endif
