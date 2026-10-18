@props(['row' => null])
{{-- Потоки ответа карточке строки — у `x-ui.row-card` и у пустого фрейма, когда карточки больше нет (удалили черновик,
     `Detail::response`). detail-gone — строка ушла из списка (заполнили закупочную или оценили — она во вкладке дальше; в
     архив, опубликовали, удалили): убрать строку таблицы или плитку с числом её группы (`drop`), а не заменить; detail-counts — новые числа у пилюль
     (выросшее вспыхивает, StreamActions.count). --}}
@if (session('detail-gone'))<turbo-stream action="drop" key="{{ \App\Support\Detail::key() }}"></turbo-stream>
@foreach ((array) session('detail-counts') as $pill => $n)<turbo-stream action="count" count="{{ (int) $n }}" targets="[data-pill-count=&quot;{{ $pill }}&quot;]"></turbo-stream>@endforeach
@elseif ($row ?? null)<turbo-stream action="replace" targets="tr[data-detail-key=&quot;{{ \App\Support\Detail::key() }}&quot;]"><template>{{ $row }}</template></turbo-stream>@endif
<x-ui.flash-stream/>
{{-- live-rows — действие тронуло и другие строки этого списка (пачка): перечитать их у автора (`StreamActions.rows`). --}}
@if ($liveRows = session('live-rows'))<turbo-stream action="rows" keys="{{ implode(',', (array) $liveRows) }}"></turbo-stream>@endif
@if (session('detail-advance'))<turbo-stream action="advance"></turbo-stream>@endif
{{-- letters — открыть окно писем с черновиком (приняли подтверждение — письмо вендору), когда карточка встанет. Письмо ждёт
     в очереди человека на сервере (`Mail\LetterPrompt`), а не в сессии: её метку съедал соседний запрос. --}}
@if ($letterPrompt = session('letters') ?? \App\Mail\LetterPrompt::take())<turbo-stream action="letters" url="{{ $letterPrompt }}"></turbo-stream>@endif
