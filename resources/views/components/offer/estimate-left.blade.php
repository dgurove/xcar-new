{{-- Срок оценки менеджером (11.10.2026): «осталось 31 ч», в последние шесть часов оранжевым, вышел — красным «просрочено
     на 5 ч» (просьба висит, пока не оценят или не снимут), short — просто «просрочено» (пилюля в строке CRM). word — слово
     перед отсчётом. --}}
@props(['estimate', 'word' => 'осталось', 'short' => false])
@php
    $due = $estimate->due_at;
    $overdue = $due->isPast();
    $soon = ! $overdue && $due->lte(now()->addHours(\App\Offers\Estimate::REMIND_HOURS));
@endphp
@if ($overdue && $short)<span {{ $attributes->merge(['class' => 'whitespace-nowrap text-danger']) }}>просрочено</span>
@elseif ($overdue)<span {{ $attributes->merge(['class' => 'nums whitespace-nowrap text-danger']) }}>просрочено на <span data-controller="timer" data-timer-since-value="{{ $due->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::left(now()->addSeconds((int) $due->diffInSeconds(now(), true)), '') }}</span></span>
@else<span {{ $attributes->merge(['class' => 'nums whitespace-nowrap '.($soon ? 'text-urgent' : 'text-ink-muted')]) }} data-controller="timer" data-timer-until-value="{{ $due->toIso8601String() }}" data-timer-done-value="просрочено" data-timer-coarse-value="true" data-timer-word-value="{{ $word }}">{{ \App\Support\Ago::left($due, $word) }}</span>@endif
