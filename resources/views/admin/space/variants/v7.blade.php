{{-- 7. Только текст: ни одной плашки — глагол, люди, задачи строками с тонкими линиями, как список iOS прямо на небе. --}}
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <h3 class="col-title" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
    @include('admin.space.variants.people', ['size' => 22])
    @if ($stage['total'])
        <div class="t-list">
            @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
                <div @class(['t-row', 'is-hot' => $t['hot']])>
                    <span class="t-name">{{ $t['title'] }}</span>
                    <span class="t-age">{{ \App\Space\Board::age($t['at']) }}</span>
                    <span class="t-sub">{{ $t['hot'] ?? ($t['ref'] ?: $t['note']) }}</span>
                </div>
            @endforeach
            @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
        </div>
    @endif
</div>
