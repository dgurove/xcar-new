{{-- Корректировки менеджеров у оценённого черновика («Оцененные», 11.10.2026) — куски для ячейки цены строки и плитки CRM;
     где какой стоит, решает ячейка:
     - proposal — своя цена менеджера лаймовой пилюлей с аватаром (несколько — первая и «+1»), её ставят после стрелки
       «620 000 →», как «закупочная → продажи»;
     - agreed — ответившие «Согласен» зелёной галочкой с числом у цены;
     - waiting — кто ещё проверяет: аватары и срок, под ценой вместо закупочной. --}}
@props(['offer', 'part'])
@php
    $estimates = $offer->relationLoaded('openEstimates') ? $offer->openEstimates->filter->isCorrection() : collect();
    $href = '/?'.http_build_query(array_filter(array_merge(request()->query(), ['vid' => 'table', 'peek' => $offer->number, 'page' => null]), fn ($v) => is_string($v) || is_int($v) ? $v !== '' : false));
@endphp
@if ($part === 'proposal' && ($proposals = $estimates->filter->proposes()->sortBy('estimated_at')->values())->isNotEmpty())
    @php $first = $proposals->first(); @endphp
    <a href="{{ $href }}" {{ $attributes->class(['pill pill-accent nums !min-h-6 !py-0 !pl-1 !pr-3 gap-1.5 text-sm align-middle']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="{{ $proposals->map(fn ($e) => $e->user->shortName().' '.\App\Support\Money::rub($e->amount))->implode(', ') }}"><x-ui.avatar :user="$first->user" :size="18"/>{{ \App\Support\Money::nums($first->amount) }}@if ($proposals->count() > 1)<span class="opacity-70">+{{ $proposals->count() - 1 }}</span>@endif</a>
@elseif ($part === 'agreed' && ($agreed = $estimates->filter->agreed())->isNotEmpty())
    <span {{ $attributes->class(['inline-flex items-center gap-0.5 align-middle text-sm text-open']) }} title="Согласны: {{ $agreed->map(fn ($e) => $e->user->shortName())->implode(', ') }}"><x-ui.icon name="check" class="size-4"/>{{ $agreed->count() }}</span>
@elseif ($part === 'waiting' && ($waiting = $estimates->reject->isGiven()->sortBy('due_at')->values())->isNotEmpty())
    <a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center gap-1.5 text-sm']) }} data-turbo-action="replace" data-turbo-prefetch="false" title="Проверяет: {{ $waiting->map(fn ($e) => $e->user->shortName())->implode(', ') }}">
        <span class="flex -space-x-1.5">@foreach ($waiting->take(3) as $e)<x-ui.avatar :user="$e->user" :size="18" class="ring-2 ring-surface"/>@endforeach</span>
        <x-offer.estimate-left :estimate="$waiting->first()" word="" short/>
    </a>
@endif
