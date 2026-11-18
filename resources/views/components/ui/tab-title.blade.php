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
     выбрал из вариантов; «›» у названия тогда нет — 17.11.2026, владелец): пустые вкладки (число есть и оно 0) пропускаются — только без фильтра (skipEmpty: числа колеса
     посчитаны с чипами текущей вкладки), дальше некуда — кнопка бледная.
     На ПК (от 1024) колесо — столбцами по клику (17.11.2026, владелец: «не скролл, а просто клик», «на пк не должно быть фокуса
     скролла на том, что выбрано»): columns — первая вкладка столбца → имя (заголовков на экране нет — владелец), выбранная —
     тёмным с «›» слева; на телефоне столбцы — `display: contents`, колесо прежнее.
     Вкладки нет в адресе (поиск) — fallback, без ˄ ˅. sections — подзаголовки
     разделов в колесе (16.11.2026, владелец выбрал вариант А макета: «между вариантами подзаголовки, которые не выбираются, а
     пролистываются»): ключ первой вкладки раздела → подпись; снап на них не встаёт (`tab_wheel_controller`); на ПК у раздела из
     одной вкладки («Закупки», «Гараж») — только линия (`is-solo`). heads — разделы, чьё имя несёт их первая вкладка (сводные
     «Все черновики», «Все предложения», «Все сделки», «Архивы», 17.11.2026, владелец: подзаголовки — «кликабельными»): над ней
     линия без подписи, в начале столбца ПК — и без линии. drop — параметры
     адреса, что не переносятся в другую вкладку (дела «Ваш ход» `todo`, `sub`); keep — наоборот, только эти (17.11.2026, шапка
     вкладок колеса: чипы у каждой вкладки свои и берутся из памяти `ListPrefs` — чужие из адреса ставили в соседней вкладке
     одноимённый чип чужим значением: «Этап» сделок в «Этап гаража», и список пустел). waits — вкладки, где мы только ждём
     (`DealTabs::WAITING`, 17.11.2026, владелец: «regular font полупрозрачный, чтобы показать, что там чисто ждать надо»). mine —
     «Только мои» (18.11.2026, владелец выбрал тумблер iOS макета): ['on', 'keys'] — включён ли и за какие вкладки человек отвечает
     (`OfferController::tabMine`); тумблер в правом нижнем углу колеса, включён — не свои пункты и пустые разделы прячутся
     (`is-mine`, `data-not-mine`), ˄ ˅ ходят только по своим. --}}
@props(['pills' => [], 'pill' => '', 'pillParam' => 'view', 'pillHome' => null, 'counts' => [], 'badges' => [], 'bumps' => [], 'fallback' => '', 'id' => 'tab-menu', 'sections' => [], 'heads' => [], 'columns' => [], 'skipEmpty' => true, 'drop' => [], 'keep' => null, 'waits' => [], 'mine' => null])
@php
    $action = '/'.ltrim(request()->path(), '/');
    $query = $keep !== null ? array_intersect_key(request()->query(), array_flip($keep)) : array_diff_key(request()->query(), array_flip($drop));
    $url = fn (array $set) => $action.'?'.str_replace('%2C', ',', http_build_query(array_filter(array_merge($query, $set), fn ($v) => $v !== null && $v !== '')));
    $href = fn ($key) => $url([$pillParam => $key === '' || $key === ($pillHome ?? array_key_first($pills)) ? null : $key, 'page' => null, 'rows' => null]);
    $keys = array_map('strval', array_keys($pills));
    $at = array_search((string) $pill, $keys, true);
    // Соседняя вкладка для ˄ ˅: пустую (число есть и оно 0) пропускаем — разбирать там нечего. С фильтром не пропускаем:
    // числа колеса посчитаны с чипами этой вкладки, а у соседней свои — пустая здесь там бывает полной.
    $mineOn = (bool) ($mine['on'] ?? false);
    $notMine = fn (string $key) => $mine !== null && ! in_array($key, $mine['keys'], true);
    // Открытая вкладка в колесе остаётся и чужой: на ней стоит фокус колеса, режим только сужает выбор.
    $hide = fn (string $key) => $notMine($key) && (string) $pill !== $key;
    $step = function (int $dir) use ($keys, $at, $counts, $skipEmpty, $mineOn, $notMine) {
        for ($i = $at + $dir; $i >= 0 && $i < count($keys); $i += $dir) {
            if ($mineOn && $notMine($keys[$i])) {
                continue;
            }
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
    // Сколько вкладок в разделе: у раздела из одной на ПК только линия. Своих в разделе нет — «Только мои» прячет и его линию.
    $size = [];
    $own = [];
    $part = null;
    foreach ($keys as $key) {
        $part = isset($sections[$key]) ? $key : (isset($columns[$key]) ? null : $part);
        if ($part !== null) {
            $size[$part] = ($size[$part] ?? 0) + 1;
            $own[$part] = ($own[$part] ?? false) || ! $hide($key);
        }
    }
@endphp
<div {{ $attributes->merge(['class' => 'tab-title'.($at !== false ? ' has-step' : '')]) }} data-controller="tab-wheel">
    <h1 class="text-xl sm:text-2xl">
        <button type="button" class="tab-title-btn" data-bump data-action="tab-wheel#open" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $id }}">
            <x-ui.icon name="chevron-right" class="tab-title-chev size-[.9em]"/>{{ $pills[$pill] ?? $fallback }}
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
    <div id="{{ $id }}" @class(['tab-wheel', 'is-mine' => $mineOn]) data-wheel role="dialog" aria-label="Вкладки" hidden>
        <x-ui.icon name="chevron-right" class="tab-wheel-chev text-xl sm:text-2xl size-[.9em]"/>
        <nav class="tab-wheel-scroll">
            @php($seenAll = false)
            @foreach ($cols as $col)
                @php($seenCol = false)
                <div class="tab-wheel-col" role="group" style="--col: {{ $loop->index }}" @if ($col['name']) aria-label="{{ $col['name'] }}" @endif>
                    @foreach ($col['keys'] as $key)
                        @php($label = $pills[$key])
                        {{-- Раздел, чьё имя несёт сама вкладка («Все черновики», `heads`; «Гараж», «Завершённые» — то же слово), — линия без подписи; в начале столбца ПК
                             и линии нет (`is-top`): вкладка и есть верх столбца. --}}
                        @isset($sections[$key])
                            {{-- «Только мои»: линия раздела, над которой в столбце (ПК) или во всём колесе (телефон) своих вкладок ещё нет, —
                                 не рисуется: колесо не начинается с черты. --}}
                            <div @if (! ($own[$key] ?? true)) data-not-mine @endif @unless ($seenCol) data-mine-top @endunless @unless ($seenAll) data-mine-first @endunless @class(['tab-wheel-sec', 'is-solo' => ($size[$key] ?? 0) === 1 || in_array($key, $heads, true), 'is-top' => $loop->first && in_array($key, $heads, true)]) aria-hidden="true"><span class="tab-wheel-sec-line"></span>@unless (in_array($key, $heads, true) || $sections[$key] === $label)<span class="tab-wheel-sec-name">{{ $sections[$key] }}</span>@endunless</div>
                        @endisset
                        @unless ($hide($key))
                            @php($seenCol = $seenAll = true)
                        @endunless
                        <a href="{{ $href($key) }}" @class(['tab-wheel-item text-xl sm:text-2xl', 'is-head' => in_array($key, $heads, true), 'is-wait' => in_array($key, $waits, true)]) data-turbo-prefetch="false" @isset($bumps[$key]) data-pill-bump="{{ $key }}:{{ $counts[$bumps[$key]] ?? 0 }}" @endisset @if ((string) $pill === $key) aria-current="true" @endif @if ($hide($key)) data-not-mine @endif>
                            {{-- Зелёное число — сразу за названием (16.11.2026, владелец: «зелёные счётчики возле заголовков, а не справа»):
                                 внутри названия, растёт вместе с ним; серое «всего» — справа. «›» у выбранной — на ПК (на телефоне она
                                 одна, на месте заголовка, `.tab-wheel-chev`). --}}
                            <span class="tab-wheel-label">@if ((string) $pill === $key)<x-ui.icon name="chevron-right" class="tab-wheel-mark size-[.9em]"/>@endif{{ $label }}@isset($badges[$key])<span class="badge nums tab-wheel-badge" data-pill-count="{{ $badges[$key] }}" @unless ($counts[$badges[$key]] ?? 0) hidden @endunless>{{ ($counts[$badges[$key]] ?? 0) ?: '' }}</span>@endisset</span>
                            <span class="tab-wheel-nums">
                                @if (array_key_exists($key, $counts))<span class="nums" data-pill-count="{{ $key }}">{{ $counts[$key] ?: '' }}</span>@endif
                            </span>
                        </a>
                    @endforeach
                </div>
            @endforeach
            @if ($mine !== null && ! $mine['keys'])
                <p class="tab-wheel-none">Вам не назначены вкладки</p>
            @endif
        </nav>
        @if ($mine !== null)
            <label class="tab-wheel-mine">
                <span>Только мои</span>
                <input type="checkbox" class="switch" @checked($mineOn)>
            </label>
        @endif
    </div>
</div>
