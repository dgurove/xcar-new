{{-- Откуда поступает — в воздухе, без карточек: значок, имя, сколько сегодня. Линии сходятся в точку входа доски. --}}
@php $icons = ['mail' => 'mail', 'migtorg' => 'cart', 'park' => 'park', 'gallery' => 'photo']; @endphp
<div class="board-sources">
    <div class="sources-label">Поступает</div>
    @foreach ($board['sources'] as $key => $src)
        <div class="src" data-node="src:{{ $key }}" data-flow="{{ $src[1] }}">
            <span class="src-ico"><x-ui.icon :name="$icons[$key]" class="size-4"/></span>
            <span class="src-name">{{ $src[0] }}</span>
            @if ($src[1] > 0)
                <span class="src-n">+{{ $src[1] }}{{ $key === 'gallery' ? ' интереса' : '' }}</span>
            @endif
        </div>
    @endforeach
    <span class="board-entry" data-node="src:in" aria-hidden="true"></span>
</div>
