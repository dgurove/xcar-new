{{-- Заголовок группы строк таблицы: на всю ширину (`colspan` — число столбцов описания), ключ группы `data-group` — по нему
     живое перечитывание узнаёт, что строка сменила группу (`live.js` patch), а ушедшая строка уменьшает число `[data-count]`
     (countKey — имя счётчика для `flip.js`, «group-…»). before — перед названием (галочка «выбрать всю группу»), слот —
     кнопка группы справа («Отправить заявку»). Прочие атрибуты — на строку (сворачиваемая группа «Наличия»). --}}
@props(['table', 'key', 'title', 'count' => null, 'countKey' => ''])
<tr {{ $attributes->class('table-group') }} data-group="{{ $key }}"><th colspan="{{ $table->span() }}"><span class="table-group-row"><span class="table-group-name">{!! trim($before ?? '') !!}{{ $title }}@if ($count !== null) <span class="nums" data-count="{{ $countKey }}">{{ $count }}</span>@endif</span>{!! trim($slot) !!}</span></th></tr>
