{{-- Сделки менеджера одним экраном — только живое: подтверждения без решения и сделки в работе (где ход менеджера —
     первыми, просьба прямо в строке). Закончившееся и не состоявшееся — в архиве, вход строкой «Архив N» в конце
     (11.10.2026). Админу — все сделки, без подтверждений и без пилюли «Покупатели»: вместо пилюль заголовок.
     Супер-покупателю — заголовок тоже: покупателей у него нет. --}}
@php
    $staff = auth()->user()->isAdmin() && ! auth()->user()->isManager();
    // Заголовок вместо пилюль «Сделки | Покупатели».
    $titled = $staff || auth()->user()->isSuperBuyer();
@endphp
<x-ui.shell title="Сделки" :phone-heading="$titled" :desktop-heading="$titled">
{{-- Админу на телефоне сортировка — справа от заголовка, а не своим рядом под ним (09.10.2026: одна кнопка на целую
     строку); на ПК список узкий — кнопка над ним, у его края. --}}
@if ($titled && $deals->isNotEmpty())
    <x-slot:actions class="!w-auto ml-auto md:hidden"><x-ui.toolbar :sort="$sort" name="deals-head"/></x-slot:actions>
@endif
<div class="flex flex-col gap-4">
    @if (! $titled || $deals->isNotEmpty())
        <div @class(['search-head', 'max-md:hidden' => $titled])>
            @unless ($titled)<div class="min-w-0 flex-1"><x-deal.tabs current="/deals"/></div>@endunless
            @if ($deals->isNotEmpty())<x-ui.toolbar :sort="$sort" name="deals"/>@endif
        </div>
    @endif
    {{-- «Узнавайте первым, что выбрали вас» — менеджеру; админу Telegram подключают в профиле. --}}
    @unless ($staff)<x-telegram.card/>@endunless

    @if ($pending->isNotEmpty())
        <section>
            <h2 class="list-head">Ждут решения</h2>
            <div class="list">
                @foreach ($pending as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'state' => false])
                @endforeach
            </div>
        </section>
    @endif

    @if ($deals->isEmpty() && $pending->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">{{ $archived ? 'Сейчас сделок в работе нет' : 'Пока ни одной сделки' }}</x-ui.empty>
    @endif

    @if ($deals->isNotEmpty())
        <section>
            <h2 class="list-head">В работе <span class="nums">{{ $deals->total() }}</span></h2>
            <div class="list">
                @foreach ($deals as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
        <x-ui.pager :of="$deals" :sizes="[]"/>
    @endif

    @if ($archived)
        <div class="list">
            <a href="/deals?view=archive" class="row">
                <span class="min-w-0 flex-1">Архив</span>
                <span class="nums text-ink-muted">{{ $archived }}</span>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        </div>
    @endif
</div>
</x-ui.shell>
