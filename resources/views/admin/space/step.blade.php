{{-- Подэтап: глагол, сколько задач, горящие — красной капсулой и обводкой карточки; нажатие — список, где эти задачи. Без
     дела — тихая полупрозрачная карточка: путь виден целиком. --}}
@php $tag = $step['href'] ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($step['href']) href="{{ $step['href'] }}" draggable="false" @endif
    @class(['conveyor-step', 'is-idle' => ! $step['n'], 'is-hot' => $step['hot']]) data-step="{{ $step['key'] }}">
    <span class="step-verb">{{ $step['title'] }}</span>
    @if ($step['hot'])
        <span class="hot-pill" title="Горят"><x-ui.icon name="fire" class="size-3.5"/>{{ $step['hot'] }}</span>
    @endif
    <span class="step-n" data-count="{{ $step['n'] }}">{{ $step['n'] }}</span>
</{{ $tag }}>
