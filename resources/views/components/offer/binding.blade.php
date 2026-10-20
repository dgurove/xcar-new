{{-- Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока — прям ПАНИКА!!!»),
     одна дверь `Offers\Binding`. Дата живёт в «Ценах» (`plain`), везде ещё — только когда горит (19.10.2026, владелец: «засрали
     интерфейс»): без вида — красной мигающей капсулой в шапке редактора и окошка; `tag` — коротко у названия в строке списка
     красным словом («⏱ 1 д 5 ч», «⏱ срок вышел», полный текст подсказкой), без капсулы и мигания. Не горит — ничего. --}}
@props(['offer', 'plain' => false, 'tag' => false, 'icon' => false])
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
@if (! $level)
@elseif ($plain)
    <span {{ $attributes->merge(['class' => $hot ? 'font-semibold text-danger' : '']) }}>{{ $date }}@if ($hot)<span class="block text-sm font-normal">{{ mb_strtolower($who) }}, {{ $word }}</span>@endif</span>
@elseif ($icon)
    {{-- Сигнал в строке на телефоне (20.10.2026): красный таймер у названия, слова — подсказкой и в карточке. --}}
    @if ($hot)<span {{ $attributes->merge(['class' => 'row-signal text-danger']) }} title="{{ $who }}, обязывающее {{ $past ? $word : 'до '.$date.', '.$word }}" aria-label="Срок обязывающего"><x-ui.icon name="timer" class="size-4"/></span>@endif
@elseif ($tag)
    {{-- В строке — красным словом с таймером, без капсулы (20.10.2026, владелец: капсула «истёк» ломала строку). --}}
    @if ($hot)<span {{ $attributes->merge(['class' => 'binding-tag']) }} title="{{ $who }}, обязывающее {{ $past ? $word : 'до '.$date.', '.$word }}"><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ $past ? 'срок вышел' : \Illuminate\Support\Str::after($word, 'осталось ') }}</span>@endif
@elseif ($hot)
    <span {{ $attributes->merge(['class' => 'pill binding-panic']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>{{ $who }}, {{ $past ? 'обязывающее '.$word : 'обязывающее до '.$date.', '.$word }}</span>
@endif
