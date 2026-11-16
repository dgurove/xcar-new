{{-- Заголовки раздела «Работа» — три слова рядом: Чаты, Почта, Оплаты (16.11.2026, владелец: «от колеса избавиться, просто три
     заголовка по горизонтали, чтобы между ними переключаться, как между трёх вкладок»; вид выбран из трёх — «крупные заголовки
     рядом», как в приложениях Apple). Размер — заголовка экрана, текущий — цветом текста, остальные серые. Зелёное число у слова —
     то, что ждёт: бейдж раздела из Nav::badges (непрочитанные чаты и письма, дела «Оплат»), нулевого нет; серых «всего» нет.
     Нажатое слово темнеет сразу, до ответа (`work_tabs_controller`). Сделки и гараж с 16.11.2026 — вкладками «Предложений».
     Модератору — только «Почта» (по галке почты): одно слово, без переключения. --}}
@props(['current'])
@php
    $badges = \App\Support\Nav::badges(auth()->user());
    $me = auth()->user();
    $titles = $me->canManageCrm() ? ['chats' => 'Чаты', 'mail' => 'Почта', 'money' => 'Оплаты']
        : array_filter(['mail' => $me->canCrmMail() ? 'Почта' : null]);
@endphp
<nav class="work-tabs" data-controller="work-tabs" aria-label="Работа">
    @foreach ($titles as $key => $label)
        <a href="/work/{{ $key }}" class="work-tab" data-action="work-tabs#pick" @if ($key === $current) aria-current="page" @endif>{{ $label }}@if ($n = $badges['/work/'.$key] ?? 0)<span class="badge nums tab-wheel-badge">{{ $n }}</span>@endif</a>
    @endforeach
</nav>
