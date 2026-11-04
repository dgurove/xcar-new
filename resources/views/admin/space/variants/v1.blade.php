{{-- 1. Парящие заголовки: глагол крупно без плашки, число рядом, люди свободно под ним; задачи — белые карточки. --}}
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="col-head">
        <div class="col-line">
            <h3 class="col-title" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
            @include('admin.space.variants.count')
        </div>
        @include('admin.space.variants.people')
    </div>
    @if ($stage['total'])
        <div class="col-tasks">
            @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
                @include('admin.space.variants.task')
            @endforeach
            @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
        </div>
    @endif
</div>
