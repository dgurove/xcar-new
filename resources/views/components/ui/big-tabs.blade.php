{{-- Разделы экрана крупными заголовками в ряд, как в App Store (17.11.2026, владелец про «Оплаты»: «как было в „Работе“ — красиво три
     заголовка»; вид и поведение — прежних заголовков «Работы» `x-admin.work-titles`, 16.11.2026). Размер — заголовка экрана, текущий —
     цветом текста, остальные серые; число у слова — что ждёт (`badges`), нулевого нет; красная точка — есть дело (`todo`). Нажатое слово
     темнеет сразу, до ответа (`big_tabs_controller`). Ставится на место h1: `x-ui.shell :heading="false"` и строкой `title-row`.
     `live` — ключ → путь счётчика навигации (`Nav::badges`): число тогда живое (`data-badge`, его обновляет `/live/badges`)
     и всегда красное — это дела человека («Сделки | Гараж | Покупатели» сайта, 18.11.2026). --}}
@props(['tabs', 'current', 'href', 'badges' => [], 'todo' => [], 'label' => null, 'live' => []])
<nav class="big-tabs" data-controller="big-tabs" @if ($label) aria-label="{{ $label }}" @endif>
    @foreach ($tabs as $key => $title)
        <a href="{{ $href($key) }}" class="big-tab" data-action="big-tabs#pick" data-turbo-action="replace" @if ($key === $current) aria-current="page" @endif>{{ $title }}@if ($path = $live[$key] ?? null)<x-ui.badge :href="$path" :badges="$badges"/>@elseif ($n = $badges[$key] ?? 0)<span @class(['badge nums tab-wheel-badge', 'big-tab-todo' => ! empty($todo[$key])])>{{ $n }}</span>@endif</a>
    @endforeach
</nav>
