{{-- Варианты вида доски конвейера (04.11.2026, владелец: «пробуй по-разному, нарисуй разные варианты и дай мне выбрать»):
     те же космос и камера, что у главной, доска — этапы-столбцы с задачами (`App\Space\Board`). Временная страница до выбора. --}}
@php
    use App\Space\Board;
    $names = Board::VARIANTS;
    $prev = $variant > 1 ? $variant - 1 : count($names);
    $next = $variant < count($names) ? $variant + 1 : 1;
@endphp
<x-ui.shell title="Варианты доски" :heading="false" :back="false" stage>
    <div class="space board-v{{ $variant }}" data-controller="space" data-space-key-value="space:variants:{{ $variant }}"
         data-space-links-value="{{ json_encode(in_array($variant, Board::LINED, true) ? Board::links() : []) }}" data-no-pull>
        <canvas class="space-back" data-space-target="back" aria-hidden="true"></canvas>
        <canvas class="space-sky" data-space-target="sky" aria-hidden="true"></canvas>
        <div class="space-world" data-space-target="world">
            <svg class="space-links" data-space-target="links" aria-hidden="true"></svg>
            <div id="conveyor" class="contents" data-space-target="conveyor">
                @if ($variant === 10)
                    @include('admin.space.variants.lanes')
                @else
                    @include('admin.space.variants.board', ['col' => 'admin.space.variants.v'.$variant])
                @endif
            </div>
        </div>
        <nav class="variant-switch" aria-label="Варианты">
            <a href="/space/variants/{{ $prev }}" class="variant-step" aria-label="Предыдущий"><x-ui.icon name="chevron-left" class="size-5"/></a>
            <div class="variant-name"><span class="variant-n">{{ $variant }}</span>{{ $names[$variant] }}</div>
            <a href="/space/variants/{{ $next }}" class="variant-step" aria-label="Следующий"><x-ui.icon name="chevron-right" class="size-5"/></a>
            <div class="variant-dots">
                @foreach ($names as $n => $name)
                    <a href="/space/variants/{{ $n }}" @class(['variant-dot', 'is-on' => $n === $variant]) title="{{ $name }}">{{ $n }}</a>
                @endforeach
            </div>
        </nav>
        <button type="button" class="space-home" data-space-target="home" data-action="space#home" aria-label="К началу доски" title="К началу доски" hidden>
            <x-ui.icon name="locate" class="size-5"/>
        </button>
    </div>
</x-ui.shell>
