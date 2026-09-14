{{-- Выбор одного из вариантов, состояние в адресе (?name=): на десктопе select с
     автоотправкой, на телефоне пилюля → шторка со списком — как сортировка в
     x-ui.toolbar. options: ключ → подпись; groups: подпись группы → ключи ('' — без
     группы); counts: ключ → число; default — ключ без параметра в адресе. Выбрано не
     default — элемент акцентный: фильтр сужает список; красится сразу (choose_controller). --}}
@props(['name', 'options', 'groups' => [], 'value', 'default', 'counts' => [], 'title', 'id'])
@php
    $action = '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $groups = $groups ?: ['' => array_keys($options)];
    $active = $value !== $default;
    $url = fn (string $key) => $action.'?'.http_build_query(array_filter(array_merge($query, [$name => $key === $default ? null : $key, 'page' => null]), fn ($v) => $v !== null && $v !== ''));
    $text = fn (string $key) => $options[$key].(isset($counts[$key]) ? ' '.$counts[$key] : '');
@endphp
<form method="get" action="{{ $action }}" class="hidden shrink-0 sm:flex" data-controller="choose" data-turbo-action="replace">
    @foreach ($query as $k => $v)@if ($k !== $name && $k !== 'page' && !is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <select name="{{ $name }}" class="field-input field-s choose !w-auto !bg-surface !pr-9" @if ($active) data-choose-active @endif data-choose-target="select" data-action="choose#change" aria-label="{{ $title }}">
        @foreach ($groups as $label => $keys)
            @if ($label !== '')<optgroup label="{{ $label }}">@endif
            @foreach ($keys as $key)<option value="{{ $key === $default ? '' : $key }}" @selected($key === $value)>{{ $text($key) }}</option>@endforeach
            @if ($label !== '')</optgroup>@endif
        @endforeach
    </select>
</form>
<div class="contents sm:hidden" data-controller="sheet choose">
    <button type="button" class="pill shrink-0 !pr-2.5" data-action="sheet#open" data-choose-target="pill" aria-controls="{{ $id }}" @if ($active) aria-current="true" @endif>{{ $text($value) }}<x-ui.icon name="chevron-down" class="size-4"/></button>
    <x-ui.sheet :id="$id" :title="$title">
        <div class="space-y-2">
            @foreach ($groups as $label => $keys)
                @if ($label !== '')<div class="pt-2 text-sm text-ink-dim">{{ $label }}</div>@endif
                @foreach ($keys as $key)
                    <a href="{{ $url($key) }}" data-turbo-action="replace" data-action="choose#pick" data-label="{{ $text($key) }}" @if ($key === $default) data-default @endif class="flex items-center justify-between rounded-full px-4 py-2.5 text-sm transition-colors active:bg-surface-3 {{ $key === $value ? 'bg-accent text-white' : 'bg-surface-2 text-ink hover:bg-surface-3' }}">
                        {{ $options[$key] }}@if (isset($counts[$key]))<span class="nums opacity-70">{{ $counts[$key] }}</span>@endif
                    </a>
                @endforeach
            @endforeach
        </div>
    </x-ui.sheet>
</div>
