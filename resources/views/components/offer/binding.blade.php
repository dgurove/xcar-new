{{-- Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока — прям ПАНИКА!!!»), одна
     дверь `Offers\Binding`. aside — серым чипом «⏱ 14.11 (2д 5ч)» (12.11.2026, начальник попросил дату справа от цены; владелец:
     слово «обязывающее» и красная мигающая капсула — «нейрослоп», «агрессивно»): цвет только у значка, нажатие — окошко, что за
     срок, до какого часа, чья беда и откуда он (`Binding::source`); pop=false — без окошка (шторка «Отдать»). Окно машины,
     страница `/offers/N`, окно вывоза. icon / tag — сигнал у названия в строке списка, только когда горит. --}}
@props(['offer', 'tag' => false, 'icon' => false, 'aside' => false, 'pop' => true])
@php
    use App\Offers\Binding;
    $until = $offer->binding_until;
    $level = $until && Binding::matters($offer) ? Binding::of($offer) : null;
    $panic = in_array($level, ['panic', 'over', 'deal'], true);
    $hot = $panic || $level === 'undecided';
    $date = $until ? Binding::date($until) : null;
    $word = $hot ? Binding::word($offer) : null;
    $past = $until?->isPast();
    // Чья беда — словом: нет покупателя, поставщику не заплачено, подтверждение ждёт решения.
    $who = match ($level) {
        'deal' => 'Поставщику не заплачено',
        'undecided' => 'Подтверждение не принято',
        default => 'Нет покупателя',
    };
@endphp
@if ($aside)
    {{-- Чипом «⏱ 14.11 (2д 5ч)» (12.11.2026, владелец: «обязывающее» словом — нейрослоп, «красивее и менее агрессивно»):
         цвет только у значка, нажатие — окошко: что это за срок, до какого часа, откуда он (`Binding::source`). --}}
    @if ($until && Binding::matters($offer))
        @php
            $at = $until->copy()->timezone(config('app.timezone'));
            $left = $past ? 'истёк' : \App\Support\Ago::left($until, '');
            $src = Binding::source($offer);
            $popId = 'binding-pop-'.$offer->number;
        @endphp
        @unless ($pop)
            {{-- Без окошка (шторка «Отдать»): тот же чип, нажимать нечего. --}}
            <span {{ $attributes->merge(['class' => 'binding-chip']) }} title="Срок обязывающего предложения: {{ $past ? 'истёк ' : 'до ' }}{{ Binding::date($until) }}">
                <x-ui.icon name="timer" @class(['size-4 shrink-0', 'text-danger' => $panic, 'text-urgent' => $level === 'undecided', 'text-ink-dim' => ! $hot])/>
                <span class="nums">{{ \App\Support\Ago::at($at, false, words: false) }} <span class="when-t">({{ $left }})</span></span>
            </span>
        @else
        <span class="contents" data-controller="menu">
            <button type="button" {{ $attributes->merge(['class' => 'binding-chip']) }} data-action="menu#toggle" aria-haspopup="dialog" aria-controls="{{ $popId }}" aria-label="Срок обязывающего предложения">
                <x-ui.icon name="timer" @class(['size-4 shrink-0', 'text-danger' => $panic, 'text-urgent' => $level === 'undecided', 'text-ink-dim' => ! $hot])/>
                <span class="nums">{{ \App\Support\Ago::at($at, false, words: false) }} <span class="when-t">({{ $left }})</span></span>
            </button>
            <div id="{{ $popId }}" class="menu binding-pop" popover data-menu-target="list" data-align="end">
                <div class="font-medium">Срок обязывающего предложения</div>
                <div class="nums">{{ $past ? 'истёк' : 'до' }} {{ Binding::html($until) }}@unless ($past), осталось {{ $left }}@endunless</div>
                @if ($hot)<div @class(['text-danger' => $panic, 'text-urgent' => ! $panic])>{{ $who }}</div>@endif
                @if ($src)
                    <div class="text-sm text-ink-muted">{{ $src['why'] ?? 'Вписали вручную' }}</div>
                    @if ($src['letter'])
                        <button type="button" class="letter-link self-start text-sm" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $offer->number }}/letters?at={{ $src['letter'] }}">Открыть письмо</button>
                    @endif
                @else
                    <div class="text-sm text-ink-muted">Источник не записан</div>
                @endif
            </div>
        </span>
        @endunless
    @endif
@elseif (! $level)
@elseif ($icon)
    {{-- Сигнал в строке на телефоне (20.10.2026): красный таймер у названия, слова — подсказкой и в карточке. --}}
    @if ($hot)<span {{ $attributes->merge(['class' => 'row-signal text-danger']) }} title="{{ $who }}, обязывающее {{ $past ? $word : 'до '.$date.', '.$word }}" aria-label="Срок обязывающего"><x-ui.icon name="timer" class="size-4"/></span>@endif
@elseif ($tag)
    {{-- В строке — красным словом с таймером, без капсулы (20.10.2026, владелец: капсула «истёк» ломала строку). --}}
    @if ($hot)<span {{ $attributes->merge(['class' => 'binding-tag']) }} title="{{ $who }}, обязывающее {{ $past ? $word : 'до '.$date.', '.$word }}"><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ $past ? 'срок вышел' : \Illuminate\Support\Str::after($word, 'осталось ') }}</span>@endif
@endif
