@php use App\Purchases\OfferState; $n = $purchase->number; $unpriced = $summary->unpriced()->count(); @endphp
<x-ui.shell :title="$purchase->title ?: $purchase->publicTitle()" :back="['Закупки', '/zakupki']">
    <div class="-mt-3 mb-6 flex items-center gap-2">
        <x-ui.pill tone="plain" :href="'/zakupki/'.$n">Машины</x-ui.pill>
        <x-ui.pill tone="plain" :href="'/zakupki/'.$n.'/predlozheniya'" current>Менеджеры</x-ui.pill>
        <a href="/zakupki/{{ $n }}/predlozheniya/xlsx" class="btn btn-s btn-quiet ml-auto" data-turbo="false"><x-ui.icon name="file" class="size-5"/> xlsx</a>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-ui.stat :value="$summary->cars->count()" label="Машин"/>
        <x-ui.stat :value="$summary->priced()" label="С предложениями"/>
        <x-ui.stat :value="$unpriced" label="Без предложений" :href="$unpriced ? '/zakupki/'.$n.'/predlozheniya?user=none' : null"/>
        <x-ui.stat :value="$summary->managers->filter(fn ($u) => $summary->stats[$u->id]['offered'])->count()" label="Предложили"/>
    </div>

    <div class="flex flex-col gap-2">
        @foreach ($summary->managers as $u)
            @php $st = $summary->stats[$u->id]; $on = $user?->id === $u->id; @endphp
            <a href="/zakupki/{{ $n }}/predlozheniya?user={{ $u->id }}" class="row items-center" data-turbo-action="replace" @if ($on) aria-current="true" @endif>
                <x-ui.avatar :user="$u" :size="40" class="shrink-0"/>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2"><span class="min-w-0 font-medium">{{ $u->name }}</span>@if ($st['chosen'])<span class="shrink-0 text-sm text-accent-text">выбрано {{ $st['chosen'] }}</span>@endif</div>
                    <div class="text-sm text-ink-muted">
                        @if ($st['offered'])предложил {{ $st['offered'] }} из {{ $st['visible'] }}@else ни одной цены из {{ $st['visible'] }}@endif
                        @if ($st['missing'] && $st['offered']) · без цены {{ $st['missing'] }}@endif
                    </div>
                </div>
                @if ($st['sum'])<span class="nums shrink-0 text-sm">{{ number_format($st['sum'], 0, '', ' ') }} ₽</span>@endif
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        @endforeach
        @if ($unpriced)
            <a href="/zakupki/{{ $n }}/predlozheniya?user=none" class="row items-center" data-turbo-action="replace" @if ($none) aria-current="true" @endif>
                <div class="min-w-0 flex-1"><div class="font-medium">Без предложений</div><div class="text-sm text-ink-muted">{{ $unpriced }} {{ \App\Support\Plural::of($unpriced, ['машина', 'машины', 'машин']) }} без единой цены</div></div>
                <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
            </a>
        @endif
    </div>

    @if ($user || $none)
        <h2 class="mt-8 mb-3 text-xl">{{ $user ? $user->name : 'Без предложений' }} <span class="nums text-sm font-normal text-ink-dim">{{ $rows->count() }}</span></h2>
        <div class="flex flex-col gap-2">
            @foreach ($rows as $row)
                @php $car = $user ? $row->car : $row; $offer = $user ? $row : null; @endphp
                <div class="row items-start">
                    <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></a>
                    <div class="min-w-0 flex-1">
                        <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $car->titleWithYear() }}</span><span class="shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span></a>
                        <div class="text-sm text-ink-muted">{{ implode(' · ', array_filter([$car->price_listing ? number_format($car->price_listing, 0, '', ' ').' ₽' : null, $car->kind->label(), $car->settlement?->name ?? $car->city])) }}</div>
                        @if ($offer)
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                <span class="nums font-semibold {{ $offer->state === OfferState::Chosen ? 'text-accent-text' : '' }}">{{ number_format($offer->amount, 0, '', ' ') }} ₽</span>
                                @if ($car->price_listing)<span class="text-ink-dim tabular-nums">{{ $offer->amount >= $car->price_listing ? '+' : '−' }}{{ number_format(abs($offer->amount - $car->price_listing), 0, '', ' ') }}</span>@endif
                                <span class="text-ink-dim">{{ $offer->created_at->translatedFormat('j M, H:i') }}</span>
                                @if ($offer->state === OfferState::Chosen)<span class="text-accent-text">выбрана</span>@endif
                                @if ($offer->comment)<span class="basis-full text-ink">{{ $offer->comment }}</span>@endif
                            </div>
                        @endif
                    </div>
                    @if ($offer?->state === OfferState::Active)<form method="post" action="/zakupki/ceny/{{ $offer->id }}/vybrat" class="shrink-0">@csrf<x-ui.button size="sm" variant="secondary">Выбрать</x-ui.button></form>@endif
                </div>
            @endforeach
        </div>
    @endif
</x-ui.shell>
