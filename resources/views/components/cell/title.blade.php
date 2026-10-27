{{-- Ячейка названия строки таблицы — одна на все списки: ссылка в карточку рядом (`x-ui.row-link`), значок типа ТС, название,
     «!» одного ключа; before — метка перед значком (лаймовая точка «поставщик ответил»), слот — значки после названия
     (горит обязывающее). strong — полужирным (непрочитанное). --}}
@props(['key', 'offer' => null, 'title' => null, 'category' => null, 'keys' => null, 'strong' => false, 'with' => []])
<x-ui.row-link :key="$key" :with="$with"><span @class(['cell-title', 'font-medium' => $strong])>{!! trim($before ?? '') !!}<x-ui.cat-icon :category="$category ?? $offer?->category()"/>{{ $title ?? $offer?->titleWithYear() }}<x-car.keys :count="$keys ?? $offer?->keys_count"/>{!! trim($slot) !!}</span></x-ui.row-link>
