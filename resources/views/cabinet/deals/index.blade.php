{{-- Сделки менеджера одним экраном: подтверждения без решения, сделки в работе (где от него ждут ответа — первыми,
     просьба прямо в строке), закончившиеся, внизу — не состоявшиеся. --}}
@php
    $active = $deals->getCollection()->filter(fn ($d) => $d->state === \App\Offers\DealState::Active)->sortBy(fn ($d) => $d->openRequirement ? 0 : 1)->values();
    $closed = $deals->getCollection()->reject(fn ($d) => $d->state === \App\Offers\DealState::Active)->values();
@endphp
<x-ui.cabinet title="Сделки">
    @if ($pending->isNotEmpty())
        <section>
            <h2 class="text-xl">Ждут решения</h2>
            <div class="mt-4 list">
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
            <h2 class="text-xl">{{ $heading }} <span class="nums text-ink-dim">{{ $list->count() }}</span></h2>
            <div class="mt-4 list">
                @foreach ($list as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
    @endforeach
    <x-ui.pager :of="$deals" :sizes="[]"/>

    @if ($lost->isNotEmpty())
        <section>
            <h2 class="text-xl">Не состоялись</h2>
            <div class="mt-4 list">
                @foreach ($lost as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'state' => true])
                @endforeach
            </div>
        </section>
    @endif
</x-ui.cabinet>
