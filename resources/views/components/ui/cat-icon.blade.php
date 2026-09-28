{{-- Тип ТС иконкой перед названием в таблицах (Category::icon, цвета — .cat-*); тип не известен — «?». Подпись — по наведению. --}}
@props(['category' => null])
<span {{ $attributes->merge(['class' => 'cat-icon '.($category ? 'cat-'.$category->value : 'cat-unknown')]) }} title="{{ $category?->label() ?? 'Тип не указан' }}"><x-ui.icon :name="$category?->icon() ?? 'cat-unknown'" class="size-full"/></span>
