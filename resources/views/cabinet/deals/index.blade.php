{{-- Сделки менеджера одним экраном: что ждёт ответа, подтверждения без решения, сами сделки, внизу — не состоявшиеся. --}}
@php $debts = $deals->filter(fn ($d) => $d->state === \App\Offers\DealState::Active && $d->openRequirement); $sections = $debts->isNotEmpty() || $pending->isNotEmpty() || $lost->isNotEmpty(); @endphp
<x-ui.cabinet title="Сделки">
    @if ($debts->isNotEmpty())
        {{-- Что ждёт ответа — строками на оранжевой подложке, первыми. --}}
        <section>
            <h2 class="text-xl">От Вас ждут</h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($debts as $deal)
                    @php $req = $deal->openRequirement; @endphp
                    <a href="/account/deals/{{ $deal->id }}" class="row bg-urgent-soft">
                        <span class="row-photo"><x-offer.photo :media="$deal->offer->mainPhoto()" sizes="72px"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-urgent">{{ $req->title }}</span>
                            <span class="row-sub"><span class="tag">{{ $deal->offer->titleWithYear() }}</span>@if ($req->due_at)<span class="tag nums" style="--tag-bg: transparent; --tag-text: var(--color-urgent); --tag-bg-d: transparent; --tag-text-d: var(--color-urgent)">{{ $req->due_at->isPast() ? 'просрочено' : 'до' }} {{ $req->due_at->translatedFormat('d.m H:i') }}</span>@endif</span>
                        </span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-urgent"/>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($pending->isNotEmpty())
        <section>
            <h2 class="text-xl">Ждут решения</h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($pending as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'state' => false])
                @endforeach
            </div>
        </section>
    @endif

    @if ($deals->isEmpty() && ! $sections)
        <x-ui.empty href="/" link="В каталог">Пока ни одной сделки</x-ui.empty>
    @elseif ($deals->isNotEmpty())
        <section>
        @if ($sections)<h2 class="text-xl">Сделки <span class="nums text-ink-dim">{{ $deals->total() }}</span></h2>@endif
        <div class="{{ $sections ? 'mt-4 ' : '' }}flex flex-col gap-2">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; $position = $offer->position(); $alarm = $position?->isOverdue() ?? false; @endphp
                <a href="/account/deals/{{ $deal->id }}" class="row">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
                        <span class="row-sub"><span class="tag nums">№ {{ $offer->number }}</span></span>
                        @if ($position && $deal->state === \App\Offers\DealState::Active)<x-route.clock :position="$position" class="mt-1 truncate"/>@endif
                    </span>
                    {{-- Правый столбик не шире трети строки: длинное имя этапа обрезается, а не выталкивает название. --}}
                    <span class="flex max-w-[45%] shrink-0 flex-col items-end gap-1.5">
                        <span class="nums font-medium">{{ \App\Support\Money::rub($deal->amount) }}</span>
                        {{-- Этап в покое серый: лайм — глагол. Красным становится просрочка. --}}
                        @if ($deal->state !== \App\Offers\DealState::Active)
                            <x-ui.pill class="!min-h-0 !py-1 text-xs" :tone="$deal->state === \App\Offers\DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
                        @else
                            <x-ui.pill class="!min-h-0 max-w-full overflow-hidden !py-1 text-xs" :tone="$alarm ? 'urgent' : 'plain'"><span class="min-w-0 truncate">{{ $position?->stage->block?->name ?? 'Идёт работа' }}</span></x-ui.pill>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
        <x-ui.pager :of="$deals" :sizes="[]"/>
        </section>
    @endif

    @if ($lost->isNotEmpty())
        <section>
            <h2 class="text-xl">Не состоялись</h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($lost as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'state' => true])
                @endforeach
            </div>
        </section>
    @endif
</x-ui.cabinet>
