{{-- 2. Стеклянные колонки: этап — высокая полупрозрачная панель, люди значками на её верхнем краю, подпись — над ним. --}}
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="pins">
        @foreach ($stage['people']->take(4) as $p)
            <span class="pin">
                <span class="pin-label">
                    {{ match ($p['type']) { 'user' => $p['model']->firstName(), 'vendor' => $p['model']->name, 'robot' => 'Авто', default => $p['label'] ?? '' } }}
                    <b>{{ $p['n'] }}</b>
                </span>
                @include('admin.space.variants.face', ['who' => $p, 'size' => 32])
            </span>
        @endforeach
        @if ($stage['people']->count() > 4)
            <span class="pin pin-more">+{{ $stage['people']->count() - 4 }}</span>
        @endif
    </div>
    <div class="glass">
        <div class="glass-head" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">
            <h3 class="col-title">{{ $stage['title'] }}</h3>
            @include('admin.space.variants.count')
        </div>
        @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
            @include('admin.space.variants.task')
        @endforeach
        @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
    </div>
</div>
