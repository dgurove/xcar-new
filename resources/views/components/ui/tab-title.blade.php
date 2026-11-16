{{-- Заголовок экрана — название текущей вкладки, по нажатию все вкладки колесом во весь экран (22.10.2026, владелец: пилюль
     у «Предложений» стало восемь, «если вкладок много, их логичнее вертикально размещать»; колесом — 30.10.2026: «как в iOS,
     как на Apple Watch», выбранная на месте заголовка, остальные мельче и приглушённые). Те же ключи и ссылки, что у
     пилюль x-ui.toolbar: pills — ключ → подпись, counts — числа, badges — лаймовый бейдж (ключ числа в counts), bumps —
     своё число для вспышки (у «Из таблицы Кати»). Числа в колесе держат `data-pill-count`, как пилюли: их тикают
     live.js и flip.js, вспыхивает само название (lib/bump.js). Бейдж в другой вкладке — точка после названия (CSS).
     У названия ни числа, ни стрелки рядом (владелец 22.10.2026: «криво, смещает всё»); что это выбор, говорит маленькая
     «›» в поле экрана слева — название не сдвигает (вечером того же дня). Колесо — tab_wheel_controller; вкладка открывается
     только нажатием (16.11.2026).
     Вкладки нет в адресе (поиск) — fallback. sections — подзаголовки
     разделов в колесе (16.11.2026, владелец выбрал вариант А макета: «между вариантами подзаголовки, которые не выбираются, а
     пролистываются»): ключ первой вкладки раздела → подпись; снап на них не встаёт (`tab_wheel_controller`). drop — параметры
     адреса, что не переносятся в другую вкладку (дела «Ваш ход» `todo`, `sub`). --}}
@props(['pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillHome' => null, 'counts' => [], 'badges' => [], 'bumps' => [], 'fallback' => '', 'id' => 'tab-menu', 'sections' => [], 'drop' => []])
@php
    $action = '/'.ltrim(request()->path(), '/');
    $query = array_diff_key(request()->query(), array_flip($drop));
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
@endphp
<div {{ $attributes->merge(['class' => 'tab-title']) }} data-controller="tab-wheel">
    <h1 class="text-xl sm:text-2xl">
        <button type="button" class="tab-title-btn" data-bump data-action="tab-wheel#open" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $id }}">
            <x-ui.icon name="chevron-right" class="tab-title-chev size-3.5"/>{{ $pills[$pill] ?? $fallback }}
        </button>
    </h1>
    <div id="{{ $id }}" class="tab-wheel" data-wheel role="dialog" aria-label="Вкладки" hidden>
        <x-ui.icon name="chevron-right" class="tab-wheel-chev size-3.5"/>
        <nav class="tab-wheel-scroll">
            @foreach ($pills as $key => $label)
                @isset($sections[$key])
                    <div class="tab-wheel-sec" aria-hidden="true"><span class="tab-wheel-sec-line"></span><span class="tab-wheel-sec-name">{{ $sections[$key] }}</span></div>
                @endisset
                <a href="{{ $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null, 'rows' => null]) }}" class="tab-wheel-item text-xl sm:text-2xl" data-turbo-prefetch="false" @isset($bumps[$key]) data-pill-bump="{{ $key }}:{{ $counts[$bumps[$key]] ?? 0 }}" @endisset @if ((string) $pill === (string) $key) aria-current="true" @endif>
                    {{-- Зелёное число — сразу за названием (16.11.2026, владелец: «зелёные счётчики возле заголовков, а не справа»):
                         внутри названия, растёт вместе с ним; серое «всего» — справа. --}}
                    <span class="tab-wheel-label">{{ $label }}@isset($badges[$key])<span class="badge nums tab-wheel-badge" data-pill-count="{{ $badges[$key] }}" @unless ($counts[$badges[$key]] ?? 0) hidden @endunless>{{ ($counts[$badges[$key]] ?? 0) ?: '' }}</span>@endisset</span>
                    <span class="tab-wheel-nums">
                        @if (array_key_exists($key, $counts))<span class="nums" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
                    </span>
                </a>
            @endforeach
        </nav>
    </div>
</div>
