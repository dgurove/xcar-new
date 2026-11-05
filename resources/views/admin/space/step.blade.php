{{-- Подэтап: глагол (у «Отправить отклики» перед ним логотип вендора), сколько задач, горящие — красной капсулой и обводкой
     карточки; подпункты — строками под глаголом со своими числами. Нажатие — список, где эти задачи (тем, кому он открыт,
     `$links`). Без дела — тихая полупрозрачная карточка: путь виден целиком. --}}
@php $href = $links ? $step['href'] : null; $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" draggable="false" @endif
    @class(['conveyor-step', 'is-idle' => ! $step['n'], 'is-hot' => $step['hot']]) data-step="{{ $step['key'] }}">
    <span class="step-line">
        @if ($step['vendor'])
            <x-vendor.logo :vendor="$step['vendor']" class="step-logo"/>
        @endif
        <span class="step-verb">{{ $step['title'] }}</span>
        @if ($step['hot'])
            <span class="hot-pill" title="Горят"><x-ui.icon name="fire" class="size-3.5"/>{{ $step['hot'] }}</span>
        @endif
        <span class="step-n" data-count="{{ $step['n'] }}">{{ $step['n'] }}</span>
    </span>
    @foreach ($step['parts'] as $part)
        <span class="step-part" data-step="{{ $part['key'] }}">
            <span class="step-part-title">{{ $part['title'] }}</span>
            <span data-count="{{ $part['n'] }}">{{ $part['n'] }}</span>
        </span>
    @endforeach
</{{ $tag }}>
