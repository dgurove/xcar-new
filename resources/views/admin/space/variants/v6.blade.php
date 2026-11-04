{{-- 6. Тепловая карта: вся куча квадратиками, цвет по давности (горящие — красные), сверху три самые горящие или давние. --}}
@php
    $lead = $stage['people']->first();
    $age = fn ($t) => match (true) { $t['hot'] !== null => 'hot', ($d = (time() - $t['at']) / 86400) < 1 => 'a0', $d < 3 => 'a1', $d < 7 => 'a2', $d < 30 => 'a3', default => 'a4' };
@endphp
<div @class(['col', 'is-empty' => ! $stage['total']])>
    <h3 class="col-title" data-node="{{ $stage['key'] }}" data-total="{{ $stage['total'] }}">{{ $stage['title'] }}</h3>
    <div class="hm-lead">
        @if ($lead)
            @include('admin.space.variants.face', ['who' => $lead, 'size' => 36])
            <span class="hm-who">
                <span class="person-name">{{ match ($lead['type']) { 'user' => $lead['model']->firstName(), 'vendor' => $lead['model']->name, 'robot' => 'Автоматически', default => $lead['label'] ?? '' } }}@if ($stage['people']->count() > 1) и ещё {{ $stage['people']->count() - 1 }}@endif</span>
                <span class="hm-n">{{ $stage['total'] }}</span>
            </span>
            @if ($stage['hot'])
                <span class="col-hot"><x-ui.icon name="fire" class="size-3.5"/>{{ $stage['hot'] }}</span>
            @endif
        @else
            <span class="hm-n">0</span>
        @endif
    </div>
    @if ($stage['total'])
        <div class="col-tasks">
            @foreach ($stage['tasks']->take(3) as $t)
                @include('admin.space.variants.task')
            @endforeach
        </div>
        @if ($stage['total'] > 3)
            <div class="hm-grid">
                @foreach ($stage['tasks']->slice(3) as $t)
                    <span class="tile {{ $age($t) }}" title="{{ $t['title'] }}, {{ $t['hot'] ?? \App\Space\Board::age($t['at']) }}"></span>
                @endforeach
            </div>
        @endif
    @endif
</div>
