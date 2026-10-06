{{-- Сделки менеджера одним экраном: подтверждения без решения, сделки в работе (где от него ждут ответа — первыми,
     просьба прямо в строке), закончившиеся, внизу — не состоявшиеся. --}}
@php
    $active = $deals->getCollection()->filter(fn ($d) => $d->state === \App\Offers\DealState::Active)->sortBy(fn ($d) => $d->openRequirement ? 0 : 1)->values();
    $closed = $deals->getCollection()->reject(fn ($d) => $d->state === \App\Offers\DealState::Active)->values();
@endphp
<x-ui.shell title="Сделки" :phone-heading="false" :desktop-heading="false">
<div class="flex max-w-[56rem] flex-col gap-4">
    <div class="search-head">
        <div class="min-w-0 flex-1"><x-deal.tabs current="/deals"/></div>
        @if ($deals->isNotEmpty())<x-ui.toolbar :sort="$sort" name="deals"/>@endif
    </div>
    <x-telegram.card/>

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

    @if ($deals->isEmpty() && $pending->isEmpty() && $lost->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Пока ни одной сделки</x-ui.empty>
    @endif

    @foreach (['В работе' => $active, 'Закончены' => $closed] as $heading => $list)
        @continue($list->isEmpty())
        <section>
            <h2 class="list-head">{{ $heading }} <span class="nums">{{ $list->count() }}</span></h2>
            <div class="list">
                @foreach ($list as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
    @endforeach
    <x-ui.pager :of="$deals" :sizes="[]"/>

    @if ($lost->isNotEmpty())
        <section>
            <h2 class="list-head">Не состоялись</h2>
            <div class="list">
                @foreach ($lost as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'state' => true])
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-ui.shell>
