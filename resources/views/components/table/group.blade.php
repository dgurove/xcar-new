{{-- Заголовок группы строк таблицы: на всю ширину (`colspan` — число столбцов описания), ключ группы `data-group` — по нему
     живое перечитывание узнаёт, что строка сменила группу (`live.js` patch), а ушедшая строка уменьшает число `[data-count]`
     (countKey — имя счётчика для `flip.js`, по умолчанию «group-ключ»: с пустым у всех групп одно имя, и после
     перечитывания `flip.js` катил число каждой группы от числа чужой — «Краснопутиловская 50 → 31 → 50», 02.11.2026). before — перед названием (галочка «выбрать всю группу»), слот —
     кнопка группы справа («Отправить заявку»). Прочие атрибуты — на строку (сворачиваемая группа «Наличия»). href — название
     ссылкой на одну эту группу, link — полный адрес группы скрепкой рядом с числом: его копируют и отдают человеку (в
     установленном приложении адресной строки нет; вкладки дел «Сделок», 09.11.2026). --}}
@props(['table', 'key', 'title', 'count' => null, 'countKey' => '', 'href' => null, 'link' => null])
<tr {{ $attributes->class('table-group') }} data-group="{{ $key }}"><th colspan="{{ $table->span() }}"><span class="table-group-row"><span class="table-group-name">{!! trim($before ?? '') !!}@if ($href)<a href="{{ $href }}" class="table-group-link" data-turbo-action="replace">{{ $title }}</a>@else{{ $title }}@endif @if ($count !== null) <span class="nums" data-count="{{ $countKey ?: 'group-'.$key }}">{{ $count }}</span>@endif @if ($link)<button type="button" class="table-group-copy" data-controller="copy" data-copy-text-value="{{ $link }}" data-copy-done-value="Ссылка в буфере" data-action="copy#copy" aria-label="Скопировать ссылку на группу" title="Скопировать ссылку"><x-ui.icon name="link" class="size-4"/></button>@endif</span>{!! trim($slot) !!}</span></th></tr>
