{{-- 9. Стопки: три задачи целиком, остальные — пачкой краёв, толщина — сколько их; люди сидят на пачке. --}}
@php
    $rest = $stage['tasks']->slice(3)->values();
    $layers = $rest->take(40);
@endphp
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <div class="col-line">
        <h3 class="col-title" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
        @include('admin.space.variants.count')
    </div>
    @if ($rest->isEmpty())
        @include('admin.space.variants.people')
    @endif
    @if ($stage['total'])
        <div class="col-tasks">
            @foreach ($stage['tasks']->take(3) as $t)
                @include('admin.space.variants.task')
            @endforeach
        </div>
    @endif
    @if ($rest->isNotEmpty())
        <div class="stack" style="--layers: {{ $layers->count() }}">
            <div class="stack-front">
                <span class="stack-n">ещё {{ $rest->count() }}</span>
                @include('admin.space.variants.people', ['max' => 3, 'size' => 26])
            </div>
            @foreach ($layers as $i => $t)
                <span @class(['stack-layer', 'is-hot' => $t['hot']]) style="--i: {{ $i + 1 }}"></span>
            @endforeach
        </div>
    @endif
</div>
