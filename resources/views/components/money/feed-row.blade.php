{{-- Строка ленты «Оплат» (06.10.2026, как история в банке): фраза, под ней как и когда (слот), справа сумма (в «Истории» —
     со знаком), у дела — серым слово действия. Нажатие — карточка счёта или поступления рядом (App\Support\Detail), как
     строка таблицы. Фраза не режется многоточием, переносится. --}}
@props(['key', 'title', 'amount', 'tone' => null, 'action' => null])
<a href="{{ \App\Support\Detail::url($key) }}" class="row" data-detail-key="{{ $key }}" data-detail-link data-turbo-frame="detail" data-turbo-action="replace" data-turbo-prefetch="false" data-search-row>
    <span class="min-w-0 flex-1">
        <span class="block break-words">{{ $title }}</span>
        @if ($slot->isNotEmpty())<span class="row-sub">{{ $slot }}</span>@endif
    </span>
    <span class="nums shrink-0 whitespace-nowrap {{ $tone }}">{{ $amount }}</span>
    @if ($action)<span class="shrink-0 text-ink-dim max-sm:hidden">{{ $action }}</span>@endif
    <x-ui.chevron/>
</a>
