{{-- «⋯» — серая пилюля 40×28 и шторка действий (12.11.2026, владелец выбрал из вариантов: круг 44 px «стрёмный», раздвигал
     строки, меню всплывало поверх содержимого). Телефон — шторка снизу как в iOS: пункты одной карточкой, группы через линию,
     опасное — последней, «Отмена»; ПК с мышью — поповер из пилюли (`x-ui.sheet anchor`). Пункты — `.menu-item` строками
     (`<div class="menu-group">` — группа), нажатие пункта закрывает шторку (`sheet#item`), его действие идёт своим ходом.
     light — белая пилюля на сером фоне страницы; bar — значком полосы окна (`bar-btn`), где «⋯» стоит рядом с «Закрыть». Одна дверь для всех «⋯»: окно машины, шаг пути, вывоз, гараж, письма, чат. --}}
@props(['id', 'label' => 'Ещё', 'light' => false, 'event' => null, 'bar' => false])
<div class="contents" data-controller="sheet" @if ($event) data-action="{{ $event }}:open@window->sheet#open" @endif>
    <button type="button" {{ $attributes->class(['bar-btn' => $bar, 'more-pill' => ! $bar, 'more-pill--light' => $light && ! $bar]) }} data-action="sheet#open" aria-label="{{ $label }}" aria-haspopup="dialog" aria-controls="{{ $id }}"><x-ui.icon name="more" :class="$bar ? 'size-[18px]' : 'size-[22px]'"/></button>
    <x-ui.sheet :id="$id" anchor bare class="sheet-actions">
        <div class="sheet-actions-list" data-action="click->sheet#item">{{ $slot }}</div>
        <button type="button" class="sheet-actions-cancel" data-action="sheet#close">Отмена</button>
    </x-ui.sheet>
</div>
