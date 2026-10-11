{{-- Корректировки менеджеров у оценённого черновика («Оцененные», 11.10.2026) — под ценой продажи в строке и плитке CRM:
     своя цена — лаймовой пилюлей с аватаром и суммой (несколько — первая и «+1»), её решает админ; иначе кто ещё
     проверяет — аватары и срок; ответили все «Согласен» — зелёным «согласны N». Нет просьб — ничего. --}}
@props(['offer'])
@php
    $estimates = $offer->relationLoaded('openEstimates') ? $offer->openEstimates->filter->isCorrection() : collect();
    $proposals = $estimates->filter->proposes()->sortBy('estimated_at')->values();
    $waiting = $estimates->reject->isGiven()->sortBy('due_at')->values();
    $agreed = $estimates->filter->agreed();
    $href = '/?'.http_build_query(array_filter(array_merge(request()->query(), ['vid' => 'table', 'peek' => $offer->number, 'page' => null]), fn ($v) => is_string($v) || is_int($v) ? $v !== '' : false));
@endphp
@if ($proposals->isNotEmpty())
    @php $first = $proposals->first(); @endphp
    <a href="{{ $href }}" {{ $attributes->class(['pill pill-accent nums !min-h-6 !py-0 !pl-1 !pr-3 gap-1.5 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="{{ $proposals->map(fn ($e) => $e->user->shortName().' '.\App\Support\Money::rub($e->amount))->implode(', ') }}"><x-ui.avatar :user="$first->user" :size="18"/>{{ \App\Support\Money::nums($first->amount) }}@if ($proposals->count() > 1)<span class="opacity-70">+{{ $proposals->count() - 1 }}</span>@endif</a>
@elseif ($waiting->isNotEmpty())
    <a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center gap-1.5 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="Проверяет: {{ $waiting->map(fn ($e) => $e->user->shortName())->implode(', ') }}">
        <span class="flex -space-x-1.5">@foreach ($waiting->take(3) as $e)<x-ui.avatar :user="$e->user" :size="18" class="ring-2 ring-surface"/>@endforeach</span>
        <x-offer.estimate-left :estimate="$waiting->first()" word="" short/>
    </a>
@elseif ($agreed->isNotEmpty())
    <span {{ $attributes->class(['text-sm text-open']) }}>согласны {{ $agreed->count() }}</span>
@endif
