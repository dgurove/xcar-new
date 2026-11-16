{{-- Шторка «Фильтр» шапки вкладок колеса «Предложений» (`x-ui.filter-sheet`, 17.11.2026): отвечает `Facets::apply` на запрос с
     `X-Filters`. Сверху «Мои фильтры» — сохранённые строками (нажатие — открыть: вкладка и все параметры разом; «⋯» —
     переименовать, обновить тем, что выбрано в шторке, удалить) и «Сохранить фильтр…». Ниже параметры строками, как «Настройки»
     iOS: чип со списком — «Менеджер — Иван Петров ›», варианты открываются в той же шторке («‹ Фильтр» — назад); числа «от–до» —
     полями в строке; переключатели — свитчами. Внизу «Сбросить» и «Показать N» (число — тот же адрес с `X-Count`).
     Логику галок держит `filter_controller` тем же правилом, что шторка чипа: отмечено всё — без фильтра, снятых меньше —
     «кроме». В адрес уходит всё выбранное; пустое — только у того, что было выбрано: иначе память `ListPrefs` подставит прошлое. --}}
@php
    use App\Support\Facets\SavedFilter;
    $pane ??= null;
    $chips = $facets->chips(true, $pane);
    $user = auth()->user();
    $saved = $facets->saved && $user ? SavedFilter::of($user, $facets->saved) : collect();
    $state = $facets->state();
    $carry = collect(request()->query())->except([...$facets->keys(), 'page', 'q', 'peek', 'part', 'rows', 'reset'])->filter(fn ($v) => is_string($v) && $v !== '')->all();
    $current = [...$carry, ...$state];
    $tabs = \App\Http\Admin\OfferController::PRESETS;
    // Подсказка названия — что выбрано сейчас: «Иван Петров, горит срок».
    $hint = $chips->filter(fn ($c) => ($c->on ?? $c->selected !== []) && ! $c->facet->byDefault)
        ->map(fn ($c) => $c->facet->toggle ? mb_strtolower($c->facet->title) : ($c->facet->range || $c->facet->dates ? $c->facet->title.' '.mb_strtolower($c->label) : $c->label))
        ->take(3)->implode(', ');
    $hint = $hint !== '' ? mb_strtoupper(mb_substr($hint, 0, 1)).mb_substr($hint, 1) : ($tabs[request()->query('preset')] ?? 'Мой фильтр');
    $panes = $chips->reject(fn ($c) => $c->facet->toggle || $c->facet->range);
@endphp
@if ($pane === null)
<div class="filter-main" data-filter-target="main">
    @if ($facets->saved)
        <div class="list-cap">Мои фильтры</div>
        <div class="list mb-4">
            @foreach ($saved as $sf)
                @php $here = $sf->matches($current); @endphp
                <div class="filter-saved">
                    <div class="row">
                        <a href="{{ $sf->url() }}" class="min-w-0 flex-1" data-turbo-prefetch="false" data-action="filter#go">
                            <span class="block truncate">{{ $sf->name }}</span>
                            <span class="row-sub">{{ $tabs[$sf->query['preset'] ?? ''] ?? 'Предложения' }}</span>
                        </a>
                        @if ($here)<x-ui.icon name="check" class="size-5 shrink-0 text-accent"/>@endif
                        <button type="button" class="filter-more" data-action="filter#more" aria-label="Изменить «{{ $sf->name }}»"><x-ui.icon name="more" class="size-5"/></button>
                    </div>
                    <div class="filter-edit" hidden>
                        <form method="post" action="/offers/filters/{{ $sf->id }}" class="flex items-center gap-2">
                            @csrf
                            @method('patch')
                            <input type="text" name="name" value="{{ $sf->name }}" maxlength="60" class="field-input field-s min-w-0 flex-1" aria-label="Название" required>
                            <button type="submit" class="btn btn-s btn-quiet shrink-0">Переименовать</button>
                        </form>
                        <div class="flex flex-wrap gap-2">
                            <form method="post" action="/offers/filters/{{ $sf->id }}" data-action="submit->filter#stamp">
                                @csrf
                                @method('patch')
                                <input type="hidden" name="query" value="">
                                <button type="submit" class="btn btn-s btn-quiet">Сохранить выбранное сюда</button>
                            </form>
                            <form method="post" action="/offers/filters/{{ $sf->id }}" data-turbo-confirm="Удалить фильтр «{{ $sf->name }}»?" data-turbo-confirm-label="Удалить">
                                @csrf
                                @method('delete')
                                <button type="submit" class="btn btn-s btn-quiet text-danger">Удалить</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="filter-saved">
                <button type="button" class="row w-full text-left text-accent-text" data-action="filter#more">
                    <x-ui.icon name="plus" class="size-5 shrink-0"/><span class="min-w-0 flex-1">Сохранить фильтр…</span>
                </button>
                <div class="filter-edit" hidden>
                    <form method="post" action="/offers/filters" class="flex items-center gap-2" data-action="submit->filter#stamp">
                        @csrf
                        <input type="hidden" name="list" value="{{ $facets->saved }}">
                        <input type="hidden" name="query" value="">
                        <input type="text" name="name" value="{{ $hint }}" maxlength="60" class="field-input field-s min-w-0 flex-1" aria-label="Название фильтра" required>
                        <button type="submit" class="btn btn-s btn-accent shrink-0">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
<form method="get" action="{{ $facets->action() }}" class="filter-form" data-filter-target="form" data-action="change->filter#change input->filter#input submit->filter#submit">
    @foreach ($carry as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    {{-- Значения чипов со списком — вне `.list`: между строками они рвали волосяные линии. --}}
    @foreach ($panes as $chip)<input type="hidden" name="{{ $chip->facet->key }}" value="{{ $chip->value }}" data-orig="{{ $state[$chip->facet->key] ?? '' }}" data-filter-value="{{ $chip->facet->key }}">@endforeach
    <div class="filter-pane" data-filter-target="pane" data-pane="">
        <div class="list">
            @foreach ($chips as $chip)
                @php $f = $chip->facet; $key = $f->key; $orig = $state[$key] ?? ''; @endphp
                @if ($f->toggle)
                    <label class="row row-switch">
                        <input type="hidden" name="{{ $key }}" value="" data-orig="{{ $orig }}">
                        <span class="min-w-0 flex-1">{{ $f->title }}</span>
                        @if ($chip->count)<span class="nums text-sm text-ink-dim">{{ $chip->count }}</span>@endif
                        <input type="checkbox" class="switch shrink-0" name="{{ $key }}" value="1" @checked($chip->selected) data-orig="{{ $orig }}">
                    </label>
                @elseif ($f->range)
                    <div class="row filter-range">
                        <span class="mr-auto">{{ $f->title }}</span>
                        <input type="hidden" name="{{ $key }}" value="{{ $chip->value }}" data-orig="{{ $orig }}" data-filter-range="{{ $key }}">
                        <input type="text" inputmode="numeric" class="field-input field-s filter-num" placeholder="от" value="{{ $chip->from !== null ? ($f->money ? \App\Support\Money::nums($chip->from) : $chip->from) : '' }}" form="filter-none" data-filter-edge="{{ $key }}" @if ($f->money) data-money @endif aria-label="{{ $f->title }} от" autocomplete="off">
                        <span class="text-ink-dim">–</span>
                        <input type="text" inputmode="numeric" class="field-input field-s filter-num" placeholder="до" value="{{ $chip->to !== null ? ($f->money ? \App\Support\Money::nums($chip->to) : $chip->to) : '' }}" form="filter-none" data-filter-edge="{{ $key }}" @if ($f->money) data-money @endif aria-label="{{ $f->title }} до" autocomplete="off">
                    </div>
                @else
                    <button type="button" class="row w-full text-left" data-action="filter#push" data-filter-pane-param="{{ $key }}">
                        <span class="shrink-0">{{ $f->title }}</span>
                        <span class="filter-value min-w-0 flex-1 truncate text-right" data-filter-label="{{ $key }}">{{ $chip->on ? $chip->label : 'Все' }}</span>
                        <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                    </button>
                @endif
            @endforeach
        </div>
    </div>
    {{-- Варианты параметров — по открытию (`filter#push`, `X-Filter-Pane`). --}}
    <div data-filter-target="panes"></div>
    <div class="filter-foot">
        <a href="{{ $facets->resetUrl() }}" class="btn btn-quiet shrink-0" data-turbo-prefetch="false" data-action="filter#go">Сбросить</a>
        <button type="submit" class="btn btn-accent min-w-0 flex-1" data-filter-target="submit">Показать <span class="nums">{{ $facets->total() }}</span></button>
    </div>
</form>
@else
    @foreach ($panes as $chip)
        @php
            $f = $chip->facet;
            $key = $f->key;
            $on = $chip->on ?? $chip->selected !== [];
            $groups = $f->groups ?: ['' => array_map(fn ($o) => $o->key, $chip->options)];
            $byKey = collect($chip->options)->keyBy('key');
        @endphp
        <div class="filter-pane" data-filter-target="pane" data-pane="{{ $key }}">
            <button type="button" class="filter-back" data-action="filter#pop"><x-ui.icon name="chevron-left" class="size-5"/>{{ $f->title }}</button>
            @if ($f->dates)
                <div class="list mb-3">
                    @foreach ($chip->options as $o)
                        <label class="row row-check">
                            <span class="min-w-0 flex-1 truncate">{{ $o->label }}</span>
                            <span class="nums text-sm text-ink-dim">{{ $o->count }}</span>
                            <span class="check"><input type="radio" name="{{ $key }}-quick" value="{{ $o->key }}" form="filter-none" @checked($o->selected) data-filter-quick="{{ $key }}" data-label="{{ $o->label }}"></span>
                        </label>
                    @endforeach
                </div>
                <div class="facet-days">
                    <div class="facet-day"><span class="text-sm text-ink-dim">С</span><x-ui.date :name="'filter-'.$key.'-from'" :value="$chip->from" placeholder="Дата" form="filter-none" data-filter-day="{{ $key }}"/></div>
                    <div class="facet-day"><span class="text-sm text-ink-dim">По</span><x-ui.date :name="'filter-'.$key.'-to'" :value="$chip->to" placeholder="Дата" form="filter-none" data-filter-day="{{ $key }}"/></div>
                </div>
                <button type="button" class="facet-all" data-action="filter#clear" data-filter-key-param="{{ $key }}">Любой</button>
            @else
                @if (! $f->single && count($chip->options) > 1)
                    <div class="facet-tools">
                        @if (count($chip->options) > 8)
                            <input type="search" class="field-input field-s min-w-0 flex-1" placeholder="Найти" autocomplete="off" enterkeyhint="search" form="filter-none" data-action="input->filter#find keydown.enter->filter#stop">
                        @endif
                        <button type="button" class="facet-all" data-action="filter#all" data-filter-key-param="{{ $key }}">{{ $on ? 'Выбрать все' : 'Исключить все' }}</button>
                    </div>
                @endif
                @foreach ($groups as $label => $keys)
                    @php $rows = collect($keys)->map(fn ($k) => $byKey->get((string) $k))->filter(); @endphp
                    @continue($rows->isEmpty())
                    @if ($label !== '')<div class="list-cap pt-2">{{ $label }}</div>@endif
                    <div class="list mb-3">
                        @foreach ($rows as $o)
                            <label class="row row-check" data-filter-item data-name="{{ mb_strtolower($o->label.' '.$o->hint) }}">
                                @if ($o->vendor)<x-vendor.logo :vendor="$o->vendor" class="facet-mark size-7 shrink-0"/>
                                @elseif ($o->user)<x-ui.avatar :user="$o->user" :size="28" class="facet-mark"/>
                                @elseif ($o->category)<x-ui.cat-icon :category="$o->category" class="facet-mark !m-0 size-6 shrink-0"/>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $o->label }}</span>
                                    @if ($o->hint)<span class="row-sub text-sm text-ink-dim">{{ $o->hint }}</span>@endif
                                </span>
                                <span class="nums text-sm text-ink-dim">{{ $o->count }}</span>
                                <span class="check">
                                    @if ($f->single)
                                        <input type="radio" name="{{ $key }}-one" form="filter-none" value="{{ $o->key === $f->default ? '' : $o->key }}" @checked($o->selected) data-filter-box="{{ $key }}" data-label="{{ $o->label }}">
                                    @else
                                        <input type="checkbox" form="filter-none" value="{{ $o->key }}" @checked($o->selected || ! $on) data-filter-box="{{ $key }}" data-label="{{ $o->label }}">
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
@endif
