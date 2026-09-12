{{-- Закладка. star — кружок на кадре карточки; compact — иконка с подписью в ряду действий.
     Turbo-форма: ответ — стрим, который заменяет форму и счётчик в шапке. --}}
@props(['offer', 'variant' => 'star'])
@php $on = $offer->isFavoriteOf(auth()->user()); $label = $on ? 'Убрать из избранного' : 'В избранное'; @endphp
<form method="post" action="/offers/{{ $offer->number }}/izbrannoe" id="fav-{{ $offer->number }}{{ $variant === 'compact' ? '-compact' : '' }}" class="contents" data-controller="favorite" data-action="turbo:submit-start->favorite#press turbo:submit-end->favorite#settle" @guest data-turbo="false" @endguest>
    @csrf
    @if ($variant === 'compact')
        <button {{ $attributes->merge(['class' => 'group inline-flex h-10 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full pl-2.5 pr-3 text-sm font-medium transition-colors sm:gap-2 sm:pl-3 sm:pr-4 '.($on ? 'bg-accent-soft text-accent-text' : 'text-ink-muted hover:bg-surface-2 hover:text-ink')]) }} aria-label="{{ $label }}">
            <x-ui.icon name="bookmark" class="size-5 {{ $on ? 'fill-accent text-accent' : 'text-ink-dim' }}"/><span>Избранное</span>
        </button>
    @else
        <button {{ $attributes->merge(['class' => 'card-star'.($on ? ' is-on' : '')]) }} aria-label="{{ $label }}" title="{{ $label }}">
            <x-ui.icon name="bookmark" class="size-5 {{ $on ? 'fill-current' : '' }}"/>
        </button>
    @endif
</form>
