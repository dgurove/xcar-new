{{-- Вкладки дел второго ряда «Сделок» CRM (09.11.2026, владелец выбрал из вариантов: «Разобрать ответ — отдельная вкладка»):
     разделы «Ваш ход» (`?todo=`) или чего ждём во «Ждём …» (`?what=`) — `DealController::todo|waiting`. Тише пилюль: без
     подложки, текущая — мягким лаймом. Пустые не рисуются (текущая — всегда); число — `data-pill-count`, его сверяет живое
     обновление (`live.js` syncCounts), ушло в ноль — вкладка прячется (`.work-tab-n:empty`). Одна группа по ссылке (`&sub=`)
     пилюлей здесь не рисуется — на телефоне вкладка и пилюля вместе не влезали, — назад ведут сама вкладка и «Показать все»
     в заголовке группы. --}}
@props(['tabs', 'current'])
<nav class="work-tabs" aria-label="Вкладки">
    @foreach ($tabs as $t)
        @continue($t['n'] === 0 && $t['key'] !== $current)
        <a href="{{ $t['href'] }}" class="work-tab" data-turbo-action="replace" @if ($t['key'] === $current) aria-current="true" @endif>{{ $t['title'] }}@if ($t['n'] !== null) <span class="nums work-tab-n" data-pill-count="{{ $t['count'] }}">{{ $t['n'] ?: '' }}</span>@endif</a>
    @endforeach
</nav>
