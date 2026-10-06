{{-- Сортировка списка (App\Support\Sort): круглая ⇅ в тулбаре и окошко, как у чипа фильтра (владелец 06.10.2026) —
     на ПК с мышью поповер у кнопки, на телефоне шторка снизу. Сверху направление сегментом «По возрастанию |
     По убыванию», ниже поля строками, у текущего — лаймовая галка. Всё — ссылки: поле включается со своим
     естественным направлением, сегмент переворачивает текущее. Сортировка не по умолчанию — кнопка отмечена.
     Предзагрузки у ссылок нет: ListPrefs запомнил бы сортировку от наведения. --}}
@props(['sort', 'url', 'name' => 'list'])
@php $id = 'sort-'.$name; @endphp
<div class="contents" data-controller="sheet">
    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0 sort-btn" data-action="sheet#open" aria-controls="{{ $id }}" aria-label="Сортировка: {{ mb_strtolower($sort->label()) }}" @unless ($sort->isDefault()) aria-current="true" @endunless><x-ui.icon name="sort" class="size-5"/></button>
    <x-ui.sheet :id="$id" title="Сортировка" anchor class="sheet-sort">
        <div class="segment mb-3">
            @foreach ([false => ['По возрастанию', 'arrow-up'], true => ['По убыванию', 'arrow-down']] as $desc => [$label, $icon])
                <a href="{{ $url($sort->toward((bool) $desc)) }}" data-turbo-action="replace" data-turbo-prefetch="false" @if ($sort->desc === (bool) $desc) aria-current="true" @endif><x-ui.icon :name="$icon" class="size-4"/>{{ $label }}</a>
            @endforeach
        </div>
        <div class="list sort-list">
            @foreach ($sort->options as $key => [$label])
                <a href="{{ $url($sort->of($key)) }}" class="row" data-turbo-action="replace" data-turbo-prefetch="false" @if ($key === $sort->key) aria-selected="true" @endif>
                    <span class="min-w-0 flex-1">{{ $label }}</span>
                    @if ($key === $sort->key)<x-ui.icon name="check" class="size-5 shrink-0 text-accent"/>@endif
                </a>
            @endforeach
        </div>
    </x-ui.sheet>
</div>
