{{-- Таблица списка (третий вид): плотные строки. Строка — <tr data-detail-key> со ссылкой a[data-detail-link] в фрейм
     карточки (x-ui.row-link, App\Support\Detail): нажатие по строке открывает карточку рядом — справа колонкой от 1024,
     нижним листом на телефоне (x-ui.shell :detail, detail_controller); view — вид списка: подробная (wide) на телефоне
     показывает все столбцы и листается вбок. Столбец «Марка, модель» (title-cap) — шириной в самое длинное название, чтобы
     оно не сокращалось, пока справа есть место; сжимается, только когда места нет: с описанием столбцов (`table`) ширины
     считает сервер по самим строкам (`Support\Table\Widths`), без него — titles (названия или число знаков) и rest. Не влезла в ширину — листается вбок (table_scroll_controller). table — описание
     столбцов (`Support\Table`): с ним на ПК таблица — сетка, и строка за экраном не считается (`Table::gridCss`).
     portion — порция списка (`Support\Portion`, 31.10.2026): строки — куском `tbody.table-part`, за ним хвост «Ещё N» с
     адресом следующей сотни (ловит `endless_controller`, кусок встаёт следующим `tbody` той же таблицы — в те же столбцы);
     кусок за экраном браузер не считает (`content-visibility` куском, а не строкой). Без порции — список целиком. --}}
@props(['head', 'id' => null, 'view' => null, 'titles' => null, 'rest' => null, 'table' => null, 'portion' => null])
@php
    $titleCh = $titles ? collect($titles)->map(fn ($t) => is_int($t) ? $t : mb_strlen((string) $t))->max() : null;
    $wide = $view === \App\Support\ListView::WIDE;
    // С описанием столбцов (`Support\Table`) на ПК — сеткой: строки за экраном не считаются (`Table::gridCss`, `.is-grid`).
    // Ширины — из самих значений, на сервере (`Support\Table\Widths`): браузер ничего не меряет, запас ширины сетка отдаёт
    // сначала названию (до самого длинного), остаток — последнему столбцу левой группы (30.10.2026: у обычной таблицы его
    // забирал `fill`, а название резалось).
    $grid = $table && ! $wide;
    // Ширины по самим значениям (`Widths`, 30.10.2026) — у любой таблицы с описанием: название шириной в самое длинное
    // (`--title-w`, вместо `titles`), прочие — `--rest` (сколько места им нужно; название сжимается, только когда места нет).
    $widths = $table ? \App\Support\Table\Widths::of((string) $slot, $table, $full) : [];
    $titleKey = $table ? collect($table->columns)->first(fn ($c) => $c->grow)?->key : null;
    if ($titleKey && isset($widths[$titleKey])) {
        // Ширина названия — полная (`$full`): таблицей (подробная) она листается вбок, и резать название незачем.
        $style = '--title-w: '.($full[$titleKey] ?? $widths[$titleKey]).'rem; --rest: '.round(array_sum(array_diff_key($widths, [$titleKey => 0])), 2).'rem;';
    } else {
        $style = $titleCh ? '--title-ch: '.$titleCh.';'.($rest ? ' --rest: '.$rest.'rem;' : '') : '';
    }
    $style = trim($style);
    // Столбцы сетки — цепочкой по ширине самой таблицы (`Table::gridCss`): не влезают — уходят по одному, строка — одна линия.
    $gridCls = $grid ? 'tg-'.substr(md5(json_encode([array_keys($table->columns), $widths, $full])), 0, 10) : null;
    $portion = $portion instanceof \App\Support\Portion ? $portion : null;
    $next = $portion?->next();
    // Свои контроллеры обёртки (прокрутка вбок, догрузка порций) — вместе с переданными (`group-fold` парковки).
    $controllers = collect(['table-scroll', $portion ? 'endless' : null, ...explode(' ', (string) $attributes->get('data-controller'))])->filter()->unique()->implode(' ');
@endphp
<div {{ $attributes->except('data-controller')->merge(['class' => 'table-wrap'.($wide ? ' is-wide' : '').($grid ? ' is-grid '.$gridCls : ''), 'data-controller' => $controllers]) }} @if ($id) id="{{ $id }}" @endif @if ($style) style="{{ $style }}" @endif>
    @if ($grid)
        <style>{!! $table->gridCss($gridCls, $widths, $full) !!}</style>
    @endif
    <div class="table-box">
        <table class="table">
            <thead>{{ $head }}</thead>
            @if ($portion)
                <tbody class="table-part" data-rows="{{ $portion->upto() }}" style="--part: {{ $portion->count() }}">{{ $slot }}</tbody>
                @if ($next)
                    <tbody class="table-more" data-endless-next="{{ $next }}"><tr><td colspan="{{ $table?->span() ?? 99 }}">Ещё {{ $portion->rest() }}…</td></tr></tbody>
                @endif
            @else
                <tbody class="table-whole">{{ $slot }}</tbody>
            @endif
        </table>
    </div>
</div>
