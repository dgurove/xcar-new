{{-- 10. Дорожки по людям: этапы столбцами, люди строками слева, в клетке — задачи человека на этом этапе. Видно, кто где
     застрял. Шапка этапов — та же линия, что у остальных вариантов. --}}
@php
    use App\Space\Board;
    $stages = collect($board['stages']);
    // Люди доски: кто держит хоть одну задачу; клетка — его задачи на этапе.
    $rows = [];
    foreach ($stages as $key => $stage) {
        foreach ($stage['tasks'] as $t) {
            if (! $t['who'] || (in_array($t['who']['type'], ['user', 'vendor'], true) && ! $t['who']['model'])) {
                continue;
            }
            $id = $t['who']['type'].':'.($t['who']['id'] ?? $t['who']['icon'] ?? '');
            $rows[$id] ??= ['who' => $t['who'], 'n' => 0, 'hot' => 0, 'cells' => []];
            $rows[$id]['n']++;
            $rows[$id]['hot'] += $t['hot'] ? 1 : 0;
            $rows[$id]['cells'][$key][] = $t;
        }
    }
    // Сотрудники XCar и автоматика — сверху, дальше менеджеры и поставщики по числу задач; хвост — одной строкой.
    $rows = collect($rows)->sortByDesc(fn ($r) => [$r['who']['type'] === 'user' && in_array($r['who']['id'], \App\Space\Owners::WORK, true), $r['n']])->values();
    $shownRows = $rows->take(14);
    $name = fn ($w) => match ($w['type']) { 'user' => $w['model']->shortName(), 'vendor' => $w['model']->name, 'robot' => 'Автоматически', default => $w['label'] ?? '' };
@endphp
<div class="board lanes" data-board>
    <div class="lanes-grid" style="--stages: {{ $stages->count() }}">
        <div class="lanes-corner">
            <div class="sources-label">Поступает</div>
            @foreach ($board['sources'] as $key => $src)
                <span class="src-mini">{{ $src[0] }}@if ($src[1] > 0) <b>+{{ $src[1] }}</b>@endif</span>
            @endforeach
        </div>
        @foreach ($stages as $key => $stage)
            <div @class(['lane-head', 'is-'.$stage['phase'], 'is-empty' => ! $stage['total']])>
                @if ($loop->first || $stages->values()[$loop->index - 1]['phase'] !== $stage['phase'])
                    <span class="lane-phase">{{ $board['phases'][$stage['phase']] }}</span>
                @endif
                <h3 class="col-title" data-node="{{ $key }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
                @include('admin.space.variants.count')
            </div>
        @endforeach
        @foreach ($shownRows as $row)
            <div class="lane-who">
                @include('admin.space.variants.face', ['who' => $row['who'], 'size' => 36])
                <span class="lane-name">{{ $name($row['who']) }}</span>
                <span class="lane-n">{{ $row['n'] }}</span>
                @if ($row['hot'])
                    <span class="person-hot">{{ $row['hot'] }}</span>
                @endif
            </div>
            @foreach ($stages as $key => $stage)
                @php $cell = collect($row['cells'][$key] ?? []); @endphp
                @if ($cell->isEmpty())
                    <div class="lane-cell is-void"></div>
                @else
                    <div @class(['lane-cell', 'is-hot' => $cell->whereNotNull('hot')->isNotEmpty()])>
                        <span class="lane-cell-top">
                            <span class="lane-cell-n">{{ $cell->count() }}</span>
                            @include('admin.space.variants.face', ['who' => $row['who'], 'size' => 20])
                        </span>
                        @foreach ($cell->take(2) as $t)
                            <span @class(['lane-task', 'is-hot' => $t['hot']])>{{ $t['title'] }}</span>
                        @endforeach
                        @if ($cell->count() > 2)
                            <span class="lane-more">ещё {{ $cell->count() - 2 }}</span>
                        @endif
                    </div>
                @endif
            @endforeach
        @endforeach
        @if ($rows->count() > $shownRows->count())
            <div class="lane-who lane-rest">Ещё {{ $rows->count() - $shownRows->count() }} {{ \App\Support\Plural::of($rows->count() - $shownRows->count(), ['человек', 'человека', 'человек']) }}</div>
        @endif
    </div>
</div>
