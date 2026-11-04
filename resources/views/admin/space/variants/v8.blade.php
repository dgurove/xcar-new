{{-- 8. Неон: контуры без заливки, этап — светящаяся лаймовая рамка, горящие задачи — красным неоном. --}}
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="n-head" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">
        <div class="col-line">
            <h3 class="col-title">{{ $stage['title'] }}</h3>
            @include('admin.space.variants.count')
        </div>
    </div>
    @include('admin.space.variants.people')
    @if ($stage['total'])
        <div class="col-tasks">
            @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
                @include('admin.space.variants.task')
            @endforeach
            @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
        </div>
    @endif
</div>
