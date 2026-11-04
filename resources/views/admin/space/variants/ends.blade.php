{{-- Чем кончается — в воздухе справа, как источники. --}}
@php $icons = ['done' => 'check-circle', 'garage' => 'key']; @endphp
<div class="board-ends">
    @foreach ($board['ends'] as $key => $end)
        <div class="end" @if ($key === 'done') data-node="end:{{ $key }}" @endif>
            <span class="src-ico"><x-ui.icon :name="$icons[$key]" class="size-4"/></span>
            <span class="src-name">{{ $end[0] }}</span>
            <span class="end-n">{{ $end[1] }}</span>
        </div>
    @endforeach
</div>
