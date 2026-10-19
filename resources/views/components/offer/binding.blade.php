{{-- Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока — прям ПАНИКА!!!»),
     одна дверь `Offers\Binding`. Дата живёт в «Ценах» (`plain`), везде ещё — только когда горит (19.10.2026, владелец: «засрали
     интерфейс»): без вида — красной мигающей капсулой в шапке редактора и окошка; `tag` — коротко у названия в строке списка
     («⏱ 1 д 5 ч», полный текст подсказкой), без мигания. Не горит — ничего. --}}
@props(['offer', 'plain' => false, 'tag' => false])
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
@elseif ($tag)
    @if ($hot)<span {{ $attributes->merge(['class' => 'binding-panic binding-tag']) }} title="{{ $who }}, обязывающее {{ $past ? $word : 'до '.$date.', '.$word }}"><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ $past ? 'истёк' : \Illuminate\Support\Str::after($word, 'осталось ') }}</span>@endif
@elseif ($hot)
    <span {{ $attributes->merge(['class' => 'pill binding-panic']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>{{ $who }}, {{ $past ? 'обязывающее '.$word : 'обязывающее до '.$date.', '.$word }}</span>
@endif
