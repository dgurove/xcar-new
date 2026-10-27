{{-- Заголовок группы строк таблицы: на всю ширину (`colspan` — число столбцов описания), ключ группы `data-group` — по нему
     живое перечитывание узнаёт, что строка сменила группу (`live.js` patch), а ушедшая строка уменьшает число `[data-count]`.
     Слот — кнопка группы справа («Отправить заявку»). --}}
@props(['table', 'key', 'title', 'count' => null])
<tr class="table-group" data-group="{{ $key }}"><th colspan="{{ $table->span() }}"><span class="table-group-row"><span class="table-group-name">{{ $title }}@if ($count !== null) <span class="nums" data-count>{{ $count }}</span>@endif</span>@if (trim($slot) !== ''){{ $slot }}@endif</span></th></tr>
