{{-- Ряд чипов фильтра (App\Support\Facets): на телефоне своей строкой под кнопками тулбара, листается вбок. Чип со
     шторкой — x-ui.facet-chip, переключатель («Непрочитанные», «Мои») — пилюля-ссылка. Выбрано хоть что-то —
     последним «Сбросить» — чипы как по умолчанию; `reset=false` — экран держит его сам (предложения CRM — значком у
     названия). --}}
@props(['facets', 'name' => 'list', 'reset' => true, 'part' => null])
@php
    // Шапка вкладок колеса (`x-ui.toolbar rows`, 17.11.2026): только ряд `toggles` — переключатели вкладки («Горит срок»,
    // «Новые»); остальное выбирают в окне «Фильтр», «Сбросить» — × у его кнопки.
    $chips = $facets->chips();
    $chips = $part === 'toggles' ? $chips->filter(fn ($c) => $c->facet->toggle || $c->facet->pills) : $chips;
@endphp
@if ($chips->isNotEmpty())
    <div class="{{ $part ? 'toolbar-row toolbar-row--'.$part : 'toolbar-chips' }}">
        <div class="flex flex-nowrap items-center gap-2">
            @foreach ($chips as $chip)
                @if ($chip->facet->pills && $part === 'toggles')
                    {{-- Пилюля на вариант (`Facet::pills`): «[аватар] Иван Орлов 5», у кого больше — левее. --}}
                    @foreach (collect($chip->options)->sortByDesc('count') as $o)
                        <a href="{{ $facets->pillUrl($chip->facet, $o->key, $o->selected) }}" class="pill facet-pill" data-turbo-action="replace" data-turbo-prefetch="false" @if ($o->selected) aria-current="true" @endif>@if ($o->user)<x-ui.avatar :user="$o->user" :size="20"/>@endif{{ $o->label }}@if ($o->count) <span class="nums opacity-70">{{ $o->count }}</span>@endif</a>
                    @endforeach
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
