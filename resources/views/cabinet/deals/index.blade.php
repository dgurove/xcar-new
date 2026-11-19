{{-- Сделки менеджера — сделки в работе, разделами «Ваш ход» / «Ждём поставщика» / «Ждём XCAR» (27.10.2026), за ними «Ждут решения» — срок приёма вышел, выбор за нами (02.11.2026). Все живые подтверждения — в
     «Моих подтверждениях», закончившееся — в архиве: входы строками в конце (11.10.2026). Админу — все сделки, без
     подтверждений и без пилюль: вместо них заголовок. --}}
@php
    use App\Support\ListView;
    $staff = auth()->user()->isAdmin() && ! auth()->user()->isManager();
@endphp
{{-- Заголовок экрана — большие заголовки раздела «Сделки | Гараж | Покупатели» (`x-deal.heads`, 18.11.2026); сортировка —
     справа в их строке, не влезла — строкой ниже у правого края. --}}
<x-ui.shell title="Сделки" :heading="false">
<div class="title-row">
    <x-deal.heads current="/deals"/>
    @if ($deals->isNotEmpty())
        <div class="title-actions"><x-ui.toolbar :sort="$sort" name="deals">
            {{-- Вид — менеджеру: таблица или строки с кадром; админу — только таблица (порциями). --}}
            @unless ($staff)<x-slot:extra><x-ui.view-switch :current="$view" :views="[ListView::TABLE, ListView::LIST]"/></x-slot:extra>@endunless
        </x-ui.toolbar></div>
    @endif
</div>
<div class="flex flex-col gap-4">
    {{-- «Узнавайте первым, что выбрали вас» — менеджеру; админу Telegram подключают в профиле. --}}
    @unless ($staff)<x-telegram.card/>@endunless

    @if ($deals->isEmpty() && $pending->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">{{ $archived ? 'Сейчас сделок в работе нет' : 'Пока ни одной сделки' }}</x-ui.empty>
    @endif

    {{-- Таблицей (по умолчанию, 18.11.2026 — как в CRM): менеджеру разделами «чей ход» заголовками групп («Ваш ход» первым),
         админу — все сделки одной группой порциями при листании. Строка ведёт на страницу сделки (`row-go`). --}}
    @if ($view === ListView::TABLE && $deals->isNotEmpty())
        <div data-controller="row-go" data-action="click->row-go#go">
            <x-ui.table :table="$table" id="deals-table" :view="$view" :portion="$staff ? $deals : null">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @if ($sections)
                    @foreach ($sections as $key => $section)
                        <x-table.group :table="$table" :key="$key" :title="$section['title']" :count="$section['deals']->count()" :ours="$key === 'mine'"/>
                        @foreach ($section['deals'] as $deal)<x-deal.site-table-row :deal="$deal" :table="$table" :task="$tasks[$deal->id] ?? null" :turn="$turns[$deal->id] ?? null" :in-garage="$key === 'garage'"/>@endforeach
                    @endforeach
                @else
                    @foreach ($deals as $deal)<x-deal.site-table-row :deal="$deal" :table="$table" staff/>@endforeach
                @endif
            </x-ui.table>
        </div>
    {{-- Строками — выбором в меню вида: те же разделы, строка с кадром. --}}
    @elseif ($sections)
        @foreach ($sections as $key => $section)
            <section>
                <h2 class="list-head">{{ $section['title'] }} <span class="nums">{{ $section['deals']->count() }}</span></h2>
                <div class="list">
                    @foreach ($section['deals'] as $deal)
                        @include('cabinet.deals.row', ['deal' => $deal, 'sectioned' => true, 'inGarage' => $key === 'garage', 'turn' => $turns[$deal->id] ?? null])
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    {{-- Срок приёма вышел, выбор за нами (02.11.2026): подтверждённое — разделом после сделок, пока не решим; резерв при
         чужой сделке — тем же видом. --}}
    @if ($pending->isNotEmpty())
        <section>
            <h2 class="list-head">Ждут решения <span class="nums">{{ $pending->count() }}</span></h2>
            <div class="list">
                @foreach ($pending as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid])
                @endforeach
            </div>
        </section>
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
