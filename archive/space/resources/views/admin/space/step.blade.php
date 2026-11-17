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
        {{-- Состав по вендорам — логотип и число справа в строке подпункта, перед общим числом (06.11.2026, владелец). --}}
        <span class="step-part" data-step="{{ $part['key'] }}">
            <span class="step-part-title">{{ $part['title'] }}</span>
            @if ($part['vendors']->isNotEmpty())
                <span class="step-mix">
                    @foreach ($part['vendors'] as $mix)
                        <span class="step-mix-item" title="{{ $mix['vendor']?->name ?? 'Без вендора' }}">@if ($mix['vendor'])<x-vendor.logo :vendor="$mix['vendor']" class="step-mix-logo"/>@else<span class="step-mix-logo"></span>@endif<span class="nums">{{ $mix['n'] }}</span></span>
                    @endforeach
                </span>
            @endif
            {{-- Есть разбивка — она вместо числа подпункта (владелец: «сумму сумм сверху оставь»); число — для живого обновления. --}}
            <span class="step-part-n" data-count="{{ $part['n'] }}" @if ($part['vendors']->isNotEmpty()) hidden @endif>{{ $part['n'] }}</span>
        </span>
    @endforeach
</{{ $tag }}>
