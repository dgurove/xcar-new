{{-- Доска: источники в воздухе слева, фазы с этапами-столбцами, выходы справа. Столбец рисует вариант (`$col`). --}}
<div class="board" data-board>
    @include('admin.space.variants.sources')
    @foreach ($board['phases'] as $phase => $label)
        <section class="board-phase is-{{ $phase }}">
            <div class="phase-label">{{ $label }}</div>
            <div class="phase-cols">
                @foreach (collect($board['stages'])->where('phase', $phase) as $stage)
                    @include($col, ['stage' => $stage])
                @endforeach
            </div>
        </section>
    @endforeach
    @include('admin.space.variants.ends')
</div>
