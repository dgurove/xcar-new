{{-- Стрелки к соседям по списку и счётчик «2/20». Соседа нет — стрелка гаснет,
     а не исчезает. Вне списка — «К списку (N)». --}}
@props(['prev' => null, 'next' => null, 'back' => null, 'index' => null, 'total' => 0, 'lg' => false])
<div {{ $attributes->merge(['class' => 'flex items-center gap-0.5 sm:gap-1']) }} data-controller="nav">
    @if ($index === null)
        @if ($back)<a href="{{ $back }}" class="nums mr-1 shrink-0 text-xs font-normal text-ink-dim transition-colors hover:text-ink sm:mr-2">К списку @if ($total > 0)({{ $total }})@endif</a>@endif
    @else
        <span class="nums mr-1 shrink-0 text-xs font-normal text-ink-dim sm:mr-2">{{ $index }}/{{ $total }}</span>
    @endif
    @foreach ([['prev', $prev, 'Предыдущее', 'chevron-left'], ['next', $next, 'Следующее', 'chevron-right']] as [$dir, $href, $label, $icon])
        @if ($href)
            <a href="{{ $href }}" rel="{{ $dir }}" data-nav-target="{{ $dir }}" class="btn btn-s btn-quiet btn-round shrink-0 {{ $lg ? 'btn-lg' : '' }}" aria-label="{{ $label }}"><x-ui.icon :name="$icon" class="size-5"/></a>
        @else
            <span class="btn btn-s btn-quiet btn-round shrink-0 opacity-30 {{ $lg ? 'btn-lg' : '' }}" aria-hidden="true"><x-ui.icon :name="$icon" class="size-5"/></span>
        @endif
    @endforeach
</div>
