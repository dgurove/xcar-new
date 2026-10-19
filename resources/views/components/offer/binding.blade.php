{{-- Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня до срока — прям ПАНИКА!!!»),
     одна дверь `Offers\Binding`. Без вида — капсулой в шапке редактора и окошка: горит или вышел — сплошным красным «Нет
     покупателя, обязывающее до …», иначе серая «Обязывающее до …»; `tag` — у названия в строке списка, только когда горит;
     `plain` — датой в строке «Цен». В сделке и гараже срок не важен — капсулы нет. --}}
@props(['offer', 'plain' => false, 'tag' => false])
@php
    use App\Offers\Binding;
    $until = $offer->binding_until;
    $level = $until ? Binding::of($offer) : null;
    $panic = in_array($level, ['panic', 'over'], true);
    $date = $until ? Binding::date($until) : null;
    $word = $panic ? Binding::word($offer) : null;
@endphp
@if (! $level || (! $plain && ! in_array($offer->state, Binding::LIVE, true)))
@elseif ($plain)
    <span {{ $attributes->merge(['class' => $panic ? 'font-semibold text-danger' : '']) }}>{{ $date }}@if ($panic)<span class="block text-sm font-normal">{{ $level === 'over' ? 'срок вышел, покупателя нет' : 'нет покупателя, '.$word }}</span>@endif</span>
@elseif ($tag)
    @if ($panic)<span {{ $attributes->merge(['class' => 'binding-panic binding-tag']) }}><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ $level === 'over' ? 'Обязывающее '.$word : 'Обязывающее: '.$word }}</span>@endif
@elseif ($panic)
    <span {{ $attributes->merge(['class' => 'pill binding-panic']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>Нет покупателя, {{ $level === 'over' ? 'обязывающее '.$word : 'обязывающее до '.$date.', '.$word }}</span>
@else
    <span {{ $attributes->merge(['class' => 'pill pill-plain']) }}><x-ui.icon name="timer" class="size-4 shrink-0"/>Обязывающее до {{ $date }}</span>
@endif
