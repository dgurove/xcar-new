{{-- Строка ленты «Оплат» (06.10.2026, как история в банке): фраза и сумма на одной линии (в «Истории» — со знаком), под
     ними как и когда (слот), у дела — серым слово действия. Нажатие — карточка счёта или поступления рядом
     (App\Support\Detail), как строка таблицы. Фраза не режется многоточием, переносится; машина в слоте — неразрывно. --}}
@props(['key', 'title', 'amount', 'tone' => null, 'action' => null, 'with' => []])
<a href="{{ \App\Support\Detail::url($key, $with) }}" {{ $attributes->merge(['class' => 'row money-row']) }} data-detail-key="{{ $key }}" data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false" data-search-row>
    <span class="money-row-main">
        <span class="money-row-line money-row-wrap">
            <span>{{ $title }}</span>
            <span class="nums {{ $tone }}">{{ $amount }}</span>
        </span>
        @if ($slot->isNotEmpty())<span class="row-sub">{{ $slot }}</span>@endif
    </span>
    @if ($action)<span class="shrink-0 text-ink-dim max-sm:hidden">{{ $action }}</span>@endif
    <x-ui.chevron/>
</a>
