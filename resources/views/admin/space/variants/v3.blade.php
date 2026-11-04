{{-- 3. Линия метро: одна линия через станции-этапы, над станцией — глагол и люди, задачи свисают билетами на нити. --}}
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="m-top">
        @include('admin.space.variants.people', ['max' => 3, 'size' => 24])
        <div class="col-line">
            <h3 class="col-title">{{ $stage['title'] }}</h3>
            @include('admin.space.variants.count')
        </div>
    </div>
    <div class="m-rail">
        <span @class(['m-station', 'is-hot' => $stage['hot']]) data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}"></span>
    </div>
    @if ($stage['total'])
        <div class="m-thread">
            @foreach ($stage['tasks']->take(\App\Space\Board::SHOWN) as $t)
                @include('admin.space.variants.task')
            @endforeach
            @include('admin.space.variants.more', ['shown' => \App\Space\Board::SHOWN])
        </div>
    @endif
</div>
