{{-- «Отменить шаг» — одна кнопка на маршрут CRM и вывоз (28.10.2026, владелец: «единые модули»): форма с переспросом
     «Отменить «Забрал»?», `data-step-back` (её же жмёт «Отменить» в тосте сразу после шага, `toast-undo`). Вид — `menu`
     (пункт «···» со значком), `inline` (кнопка в самом шаге), `row` (строка группы внизу карточки). `track` — ветка маршрута
     CRM, `kind` — `transport` у вывоза. «Снять …» спрашивает «Снять», остальное — «Отменить». --}}
@props(['action', 'label', 'variant' => 'menu', 'track' => null, 'kind' => null, 'text' => null, 'button' => null])
@php $verb = str_starts_with($label, 'Снять') ? 'Снять' : (str_starts_with($label, 'Вернуть') ? 'Вернуть' : 'Отменить'); @endphp
<form method="post" action="{{ $action }}" class="contents" data-step-back="{{ $kind }}" data-turbo-confirm="{{ $label }}?" @if ($text) data-turbo-confirm-text="{{ $text }}" @endif data-turbo-confirm-label="{{ $verb }}">
    @csrf
    @if ($track)<input type="hidden" name="track" value="{{ $track }}">@endif
    @if ($variant === 'menu')
        <button class="menu-item w-full" role="menuitem" data-action="menu#close"><x-ui.icon name="undo" class="size-4"/>{{ $label }}</button>
    @elseif ($variant === 'inline')
        <button class="btn btn-s btn-quiet"><x-ui.icon name="undo" class="size-4"/>{{ $button ?? $verb }}</button>
    @else
        <button class="row w-full text-left"><x-ui.icon name="undo" class="size-4 text-ink-muted"/>{{ $label }}</button>
    @endif
</form>
