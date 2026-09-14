{{-- Стрелки к соседям по списку и счётчик «2/20». Соседа нет — стрелка гаснет,
     а не исчезает. Сосед заменяет запись истории (replace): сколько ни листай,
     «‹ Назад» в шапке (x-ui.shell back) один раз — в список. --}}
@props(['prev' => null, 'next' => null, 'index' => null, 'total' => 0, 'lg' => false])
<div {{ $attributes->merge(['class' => 'flex items-center gap-0.5 sm:gap-1']) }} data-controller="nav">
    @if ($index !== null)
        <span class="nums mr-1 shrink-0 text-xs font-normal text-ink-dim sm:mr-2">{{ $index }}/{{ $total }}</span>
    @endif
    @foreach ([['prev', $prev, 'Предыдущее', 'chevron-left'], ['next', $next, 'Следующее', 'chevron-right']] as [$dir, $href, $label, $icon])
        @if ($href)
            <a href="{{ $href }}" rel="{{ $dir }}" data-nav-target="{{ $dir }}" data-turbo-action="replace" class="btn btn-s btn-quiet btn-round shrink-0 {{ $lg ? 'btn-lg' : '' }}" aria-label="{{ $label }}"><x-ui.icon :name="$icon" class="size-5"/></a>
        @else
            <span class="btn btn-s btn-quiet btn-round shrink-0 opacity-30 {{ $lg ? 'btn-lg' : '' }}" aria-hidden="true"><x-ui.icon :name="$icon" class="size-5"/></span>
        @endif
    @endforeach
</div>
