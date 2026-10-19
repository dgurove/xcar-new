{{-- Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока — прям ПАНИКА!!!»),
     одна дверь `Offers\Binding`. Без вида — капсулой в шапке редактора и окошка: горит всерьёз (нет покупателя или сделка не
     заплатила поставщику) — сплошным красным, иначе серая «Обязывающее до …»; `tag` — у названия в строке списка, когда
     горит (и подтверждение не принято — `undecided`); `plain` — датой в строке «Цен». Срок не важен — продажа ушла и
     поставщику заплачено (`Binding::matters`) — ничего нет. --}}
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
    @if ($hot)<span {{ $attributes->merge(['class' => 'binding-panic binding-tag']) }}><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ $past ? 'Обязывающее '.$word : 'Обязывающее: '.$word }}</span>@endif
@elseif ($hot)
    <span {{ $attributes->merge(['class' => 'pill binding-panic']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>{{ $who }}, {{ $past ? 'обязывающее '.$word : 'обязывающее до '.$date.', '.$word }}</span>
@else
    <span {{ $attributes->merge(['class' => 'pill pill-plain']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>Обязывающее до {{ $date }}</span>
@endif
