{{-- «Отклонить» на плашке «Предложено из заявок на приём» из окошка: черновика больше нет, карточку не нарисовать — строка
     уходит потоком, числа пилюль тикают, окно идёт к следующей строке (как `detail-gone` у `x-ui.row-card`). --}}
<turbo-stream action="remove" targets="tr[data-detail-key=&quot;{{ $number }}&quot;], article[data-detail-key=&quot;{{ $number }}&quot;]"></turbo-stream>
@foreach ($counts as $pill => $n)<turbo-stream action="count" count="{{ (int) $n }}" targets="[data-pill-count=&quot;{{ $pill }}&quot;]"></turbo-stream>@endforeach
<turbo-stream action="toast" data-message="ТС осталась на парковке"></turbo-stream>
<turbo-stream action="advance"></turbo-stream>
