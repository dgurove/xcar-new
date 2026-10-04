{{-- Чип фильтра со шторкой. Не выбран — «Менеджер ▾»; выбран — само значение лаймом («Иван Петров», «2 менеджера»)
     и × рядом: кнопка в кнопке не бывает, поэтому чип — две части в одной капсуле. В шторке — галки по вариантам
     списка с числом справа, поиск сверху, когда вариантов больше 8, и «Показать N»: число переспрашивает
     facets_controller тем же адресом с X-Count. Один выбор (single) — радио, применяется сразу. На ПК с мышью шторка
     открывается поповером у чипа (x-ui.sheet anchor), на телефоне — снизу. --}}
@props(['chip', 'facets', 'id'])
@php
    $f = $chip->facet;
    $key = $f->key;
    $on = $chip->on ?? $chip->selected !== [];
    $groups = $f->groups ?: ['' => array_map(fn ($o) => $o->key, $chip->options)];
    $byKey = collect($chip->options)->keyBy('key');
@endphp
<div class="contents" data-controller="sheet">
    @if ($on && ! $f->single)
        <span class="pill facet-on" aria-current="true">
            <button type="button" class="facet-open" data-action="sheet#open" aria-controls="{{ $id }}">{{ $chip->label }}</button>
            <a href="{{ $facets->url([$key => '']) }}" class="facet-x" data-turbo-action="replace" data-turbo-prefetch="false" aria-label="Снять «{{ $f->title }}»"><x-ui.icon name="x" class="size-3.5"/></a>
        </span>
    @else
        <button type="button" class="pill !pr-2.5" data-action="sheet#open" aria-controls="{{ $id }}" @if ($on) aria-current="true" @endif>{{ $chip->label }}<x-ui.icon name="chevron-down" class="size-4"/></button>
    @endif
    <x-ui.sheet :id="$id" :title="$f->title" anchor>
        <form method="get" action="{{ $facets->action() }}" data-turbo-action="replace" data-turbo-prefetch="false" data-controller="facets" data-action="change->facets#tick" @if ($f->single) data-facets-single-value="true" @endif>
            @foreach ($facets->carry($key) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @unless ($f->single)<input type="hidden" name="{{ $key }}" value="{{ $chip->value ?? implode(',', $chip->selected) }}" data-facets-target="value">@endunless
            {{-- Фильтр не включён — отмечено всё: показано всё, снимают лишнее. Справа кнопка «Выбрать все» / «Исключить все». --}}
            @if (! $f->single && count($chip->options) > 1)
                <div class="facet-tools">
                    @if (count($chip->options) > 8)
                        <input type="search" class="field-input field-s min-w-0 flex-1" placeholder="Найти" autocomplete="off" enterkeyhint="search" data-action="input->facets#filter keydown.enter->facets#stop">
                    @endif
                    <button type="button" class="facet-all" data-facets-target="all" data-action="facets#all">{{ $on ? 'Выбрать все' : 'Исключить все' }}</button>
                </div>
            @endif
            @foreach ($groups as $label => $keys)
                @php $rows = collect($keys)->map(fn ($k) => $byKey->get((string) $k))->filter(); @endphp
                @continue($rows->isEmpty())
                @if ($label !== '')<div class="list-cap pt-2">{{ $label }}</div>@endif
                <div class="list mb-3">
                    @foreach ($rows as $o)
                        <label class="row row-check" data-facets-target="item" data-name="{{ mb_strtolower($o->label.' '.$o->hint) }}">
                            @if ($o->vendor)<x-vendor.logo :vendor="$o->vendor" class="size-7 shrink-0"/>
                            @elseif ($o->user)<x-ui.avatar :user="$o->user" :size="28"/>
                            @elseif ($o->category)<x-ui.cat-icon :category="$o->category" class="!m-0 size-6 shrink-0"/>
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $o->label }}</span>
                                @if ($o->hint)<span class="row-sub text-sm text-ink-dim">{{ $o->hint }}</span>@endif
                            </span>
                            <span class="nums text-sm text-ink-dim">{{ $o->count }}</span>
                            <span class="check">
                                @if ($f->single)
                                    <input type="radio" name="{{ $key }}" value="{{ $o->key === $f->default ? '' : $o->key }}" @checked($o->selected) data-facets-target="box">
                                @else
                                    <input type="checkbox" value="{{ $o->key }}" @checked($o->selected || ! $on) data-facets-target="box">
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endforeach
            @unless ($f->single)
                <div class="facet-submit">
                    <button type="submit" class="btn btn-accent w-full" data-facets-target="submit">Показать <span class="nums">{{ $facets->total() }}</span></button>
                </div>
            @endunless
        </form>
    </x-ui.sheet>
</div>
