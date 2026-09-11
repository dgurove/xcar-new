@props(['items', 'current', 'param' => 'sort'])
<form method="get" class="shrink-0" data-controller="autosubmit">
    @foreach (request()->except($param, 'page') as $k => $v)
        @if (!is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <select name="{{ $param }}" class="field-input !min-h-9 !py-1.5 !text-sm !bg-surface" data-action="autosubmit#submit" aria-label="Сортировка">
        @foreach ($items as $key => $label)
            <option value="{{ $key }}" @selected($current === $key)>{{ $label }}</option>
        @endforeach
    </select>
</form>
