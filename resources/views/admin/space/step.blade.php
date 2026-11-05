{{-- Подэтап: глагол (у «Отправить отклики» перед ним логотип вендора), сколько задач, горящие — красной капсулой и обводкой
     карточки; подпункты — строками под глаголом со своими числами. Нажатие — список, где эти задачи (тем, кому он открыт,
     `$links`). Без дела — тихая полупрозрачная карточка: путь виден целиком. `data-by` — кто его делает и сколько у каждого:
     по нему фильтр «Ответственный» прячет чужое и пишет число выбранного (`space/filter.js`); капсула горящих есть всегда —
     у выбранного они бывают и при пустой у всех. --}}
@php $href = $links ? $step['href'] : null; $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" draggable="false" @endif
    @class(['conveyor-step', 'is-idle' => ! $step['n'], 'is-hot' => $step['hot']]) data-step="{{ $step['key'] }}"
    data-by="{{ json_encode($step['by'], JSON_FORCE_OBJECT) }}">
    <span class="step-line">
        @if ($step['vendor'])
            <x-vendor.logo :vendor="$step['vendor']" class="step-logo"/>
        @endif
        <span class="step-verb">{{ $step['title'] }}</span>
        <span class="hot-pill" title="Горят" @if (! $step['hot']) hidden @endif><x-ui.icon name="fire" class="size-3.5"/><span data-hot>{{ $step['hot'] }}</span></span>
        <span class="step-n" data-count="{{ $step['n'] }}">{{ $step['n'] }}</span>
    </span>
    @foreach ($step['parts'] as $part)
        <span class="step-part" data-step="{{ $part['key'] }}">
            <span class="step-part-title">{{ $part['title'] }}</span>
            <span data-count="{{ $part['n'] }}">{{ $part['n'] }}</span>
        </span>
    @endforeach
</{{ $tag }}>
