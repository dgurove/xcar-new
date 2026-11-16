{{-- Заголовок экрана — название текущей вкладки, по нажатию все вкладки колесом во весь экран (22.10.2026, владелец: пилюль
     у «Предложений» стало восемь, «если вкладок много, их логичнее вертикально размещать»; колесом — 30.10.2026: «как в iOS,
     как на Apple Watch», выбранная на месте заголовка, остальные мельче и приглушённые). Те же ключи и ссылки, что у
     пилюль x-ui.toolbar: pills — ключ → подпись, counts — числа, badges — лаймовый бейдж (ключ числа в counts), bumps —
     своё число для вспышки (у «Из таблицы Кати»). Числа в колесе держат `data-pill-count`, как пилюли: их тикают
     live.js и flip.js, вспыхивает само название (lib/bump.js). Бейдж в другой вкладке — точка после названия (CSS).
     У названия числа нет (владелец 22.10.2026: «криво, смещает всё»); что это выбор, говорит маленькая
     «›» в поле экрана слева — название не сдвигает (вечером того же дня). Колесо — tab_wheel_controller; вкладка открывается
     только нажатием (16.11.2026).
     Справа от названия на ПК (на телефоне нет) — ˄ ˅ к соседней вкладке колеса (17.11.2026, владелец: «в этой вкладке всё раскидал, переходишь к
     следующей»; те же кнопки, что ˄ ˅ в полосе окна машины; едут за концом названия — сдвигать само название нельзя, владелец
     выбрал из вариантов): пустые вкладки (число есть и оно 0) пропускаются — только без фильтра (skipEmpty: числа колеса
     посчитаны с чипами текущей вкладки), дальше некуда — кнопка бледная.
     На ПК (от 1024) колесо — столбцами по клику (17.11.2026, владелец: «не скролл, а просто клик», «на пк не должно быть фокуса
     скролла на том, что выбрано»): columns — первая вкладка столбца → имя (заголовков на экране нет — владелец), выбранная —
     тёмным с «›» слева; на телефоне столбцы — `display: contents`, колесо прежнее.
     Вкладки нет в адресе (поиск) — fallback, без ˄ ˅. sections — подзаголовки
     разделов в колесе (16.11.2026, владелец выбрал вариант А макета: «между вариантами подзаголовки, которые не выбираются, а
     пролистываются»): ключ первой вкладки раздела → подпись; снап на них не встаёт (`tab_wheel_controller`); на ПК у раздела из
     одной вкладки («Закупки», «Гараж») — только линия (`is-solo`); «Сделка» и «Архив» — подзаголовками и в начале столбца. drop — параметры
     адреса, что не переносятся в другую вкладку (дела «Ваш ход» `todo`, `sub`); keep — наоборот, только эти (17.11.2026, шапка
     вкладок колеса: чипы у каждой вкладки свои и берутся из памяти `ListPrefs` — чужие из адреса ставили в соседней вкладке
     одноимённый чип чужим значением: «Этап» сделок в «Этап гаража», и список пустел). --}}
@props(['pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillHome' => null, 'counts' => [], 'badges' => [], 'bumps' => [], 'fallback' => '', 'id' => 'tab-menu', 'sections' => [], 'columns' => [], 'skipEmpty' => true, 'drop' => [], 'keep' => null])
@php
    $action = '/'.ltrim(request()->path(), '/');
    $query = $keep !== null ? array_intersect_key(request()->query(), array_flip($keep)) : array_diff_key(request()->query(), array_flip($drop));
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
    $href = fn ($key) => $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null, 'rows' => null]);
    $keys = array_map('strval', array_keys($pills));
    $at = array_search((string) $pill, $keys, true);
    // Соседняя вкладка для ˄ ˅: пустую (число есть и оно 0) пропускаем — разбирать там нечего. С фильтром не пропускаем:
    // числа колеса посчитаны с чипами этой вкладки, а у соседней свои — пустая здесь там бывает полной.
    $step = function (int $dir) use ($keys, $at, $counts, $skipEmpty) {
        for ($i = $at + $dir; $i >= 0 && $i < count($keys); $i += $dir) {
            if (! $skipEmpty || ! array_key_exists($keys[$i], $counts) || $counts[$keys[$i]]) {
                return $keys[$i];
            }
        }

        return null;
    };
    $prev = $at === false ? null : $step(-1);
    $next = $at === false ? null : $step(1);
    // Столбцы ПК: вкладки подряд от первой вкладки столбца; без columns — один столбец.
    $cols = [];
    foreach ($keys as $key) {
        if (isset($columns[$key]) || ! $cols) {
            $cols[] = ['name' => $columns[$key] ?? null, 'keys' => []];
        }
        $cols[array_key_last($cols)]['keys'][] = $key;
    }
    // Сколько вкладок в разделе: у раздела из одной на ПК только линия.
    $size = [];
    $part = null;
    foreach ($keys as $key) {
        $part = isset($sections[$key]) ? $key : (isset($columns[$key]) ? null : $part);
        if ($part !== null) {
            $size[$part] = ($size[$part] ?? 0) + 1;
        }
    }
@endphp
<div {{ $attributes->merge(['class' => 'tab-title']) }} data-controller="tab-wheel">
    <h1 class="text-xl sm:text-2xl">
        <button type="button" class="tab-title-btn" data-bump data-action="tab-wheel#open" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $id }}">
            <x-ui.icon name="chevron-right" class="tab-title-chev size-3.5"/>{{ $pills[$pill] ?? $fallback }}
        </button>
    </h1>
    @if ($at !== false)
        <div class="tab-step text-xl sm:text-2xl">
            @foreach ([[$prev, 'Предыдущая', 'rotate-180'], [$next, 'Следующая', '']] as [$key, $word, $turn])
                @if ($key !== null)
                    <a href="{{ $href($key) }}" class="bar-btn bar-btn-s" data-turbo-action="replace" data-turbo-prefetch="false" aria-label="{{ $word }}: {{ $pills[$key] }}" title="{{ $pills[$key] }}"><x-ui.icon name="chevron-down" class="size-4 {{ $turn }}"/></a>
                @else
                    <span class="bar-btn bar-btn-s" aria-disabled="true"><x-ui.icon name="chevron-down" class="size-4 {{ $turn }}"/></span>
                @endif
            @endforeach
        </div>
    @endif
    <div id="{{ $id }}" class="tab-wheel" data-wheel role="dialog" aria-label="Вкладки" hidden>
        <x-ui.icon name="chevron-right" class="tab-wheel-chev size-3.5"/>
        <nav class="tab-wheel-scroll">
            @foreach ($cols as $col)
                <div class="tab-wheel-col" role="group" @if ($col['name']) aria-label="{{ $col['name'] }}" @endif>
                    @foreach ($col['keys'] as $key)
                        @php($label = $pills[$key])
                        @isset($sections[$key])
                            <div @class(['tab-wheel-sec', 'is-solo' => ($size[$key] ?? 0) === 1]) aria-hidden="true"><span class="tab-wheel-sec-line"></span><span class="tab-wheel-sec-name">{{ $sections[$key] }}</span></div>
                        @endisset
                        <a href="{{ $href($key) }}" class="tab-wheel-item text-xl sm:text-2xl" data-turbo-prefetch="false" @isset($bumps[$key]) data-pill-bump="{{ $key }}:{{ $counts[$bumps[$key]] ?? 0 }}" @endisset @if ((string) $pill === $key) aria-current="true" @endif>
                            {{-- Зелёное число — сразу за названием (16.11.2026, владелец: «зелёные счётчики возле заголовков, а не справа»):
                                 внутри названия, растёт вместе с ним; серое «всего» — справа. «›» у выбранной — на ПК (на телефоне она
                                 одна, на месте заголовка, `.tab-wheel-chev`). --}}
                            <span class="tab-wheel-label">@if ((string) $pill === $key)<x-ui.icon name="chevron-right" class="tab-wheel-mark size-3.5"/>@endif{{ $label }}@isset($badges[$key])<span class="badge nums tab-wheel-badge" data-pill-count="{{ $badges[$key] }}" @unless ($counts[$badges[$key]] ?? 0) hidden @endunless>{{ ($counts[$badges[$key]] ?? 0) ?: '' }}</span>@endisset</span>
                            <span class="tab-wheel-nums">
                                @if (array_key_exists($key, $counts))<span class="nums" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
    </div>
</div>
