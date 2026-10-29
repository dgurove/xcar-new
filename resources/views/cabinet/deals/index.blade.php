{{-- Сделки менеджера — только сделки в работе, разделами «Ваш ход» / «Ждём поставщика» / «Ждём XCar» (27.10.2026). Подтверждения — в
     «Моих подтверждениях», закончившееся — в архиве: входы строками в конце (11.10.2026). Админу — все сделки, без
     подтверждений и без пилюль: вместо них заголовок. --}}
@php
    $staff = auth()->user()->isAdmin() && ! auth()->user()->isManager();
    // Заголовок вместо пилюль «Сделки | Покупатели | Чаты» — только админу: супер-покупателю пилюли «Сделки | Чаты».
    $titled = $staff;
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
            @unless ($titled)<div class="search-lead min-w-0 flex-1"><x-deal.tabs current="/deals"/></div>@endunless
            {{-- Телефон: пилюлям (Сделки, Покупатели, Чаты) нужна вся ширина — сортировка у заголовка «В работе». --}}
            @if ($deals->isNotEmpty())<x-ui.toolbar :sort="$sort" name="deals" @class(['max-md:hidden' => ! $titled])/>@endif
        </div>
    @endif
    {{-- «Узнавайте первым, что выбрали вас» — менеджеру; админу Telegram подключают в профиле. --}}
    @unless ($staff)<x-telegram.card/>@endunless

    @if ($deals->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">{{ $archived ? 'Сейчас сделок в работе нет' : 'Пока ни одной сделки' }}</x-ui.empty>
    @endif

    {{-- Менеджеру — разделами «чей ход» (27.10.2026): «Ваш ход» первым — что сделать и до когда, ниже — кого ждём. --}}
    @if ($sections)
        @foreach ($sections as $key => $section)
            <section>
                <h2 class="list-head">{{ $section['title'] }} <span class="nums">{{ $section['deals']->count() }}</span>@if ($loop->first)<span class="ml-auto md:hidden"><x-ui.toolbar :sort="$sort" name="deals-list"/></span>@endif</h2>
                <div class="list">
                    @foreach ($section['deals'] as $deal)
                        @include('cabinet.deals.row', ['deal' => $deal, 'sectioned' => true])
                    @endforeach
                </div>
            </section>
        @endforeach
    @elseif ($deals->isNotEmpty())
        <section>
            <h2 class="list-head">В работе <span class="nums">{{ $deals->total() }}</span>@unless ($titled)<span class="ml-auto md:hidden"><x-ui.toolbar :sort="$sort" name="deals-list"/></span>@endunless</h2>
            <div class="list">
                @foreach ($deals as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
        <x-ui.pager :of="$deals" :sizes="[]"/>
    @endif

    {{-- Подтверждения и архив — строками в конце (11.10.2026): подтверждение — ещё не сделка, архив — уже не работа. --}}
    @if ($bids || $archived)
        <div class="list">
            @if ($bids)
                <a href="/deals?view=bids" class="row">
                    <span class="min-w-0 flex-1">Мои подтверждения</span>
                    <span class="nums text-ink-muted">{{ $bids }}</span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endif
            @if ($archived)
                <a href="/deals?view=archive" class="row">
                    <span class="min-w-0 flex-1">Архив</span>
                    <span class="nums text-ink-muted">{{ $archived }}</span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endif
        </div>
    @endif
</div>
</x-ui.shell>
