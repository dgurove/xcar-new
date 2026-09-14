{{-- Оценка машины, сверху вниз: заголовок со стрелками (соседи среди машин без нашей цены), галерея,
     факты чипами, блок цены (обе цены Carcade табличкой, предложения менеджеров, поле и «Дальше»),
     характеристики, описание. Поле в потоке страницы, не в action-bar: iOS сам подкручивает к нему,
     а фиксированная полоса внизу уходит под клавиатуру. «Дальше» сохраняет и ведёт к следующей без цены. --}}
@php $n = $purchase->number; $live = $car->activeOfferList()->sortByDesc('amount'); $photos = $car->visiblePhotos(); @endphp
<x-ui.shell :title="$car->titleWithYear()" :heading="false" :back="['Закупка', '/zakupki/'.$n.'?preset=unfinal']">
    <div class="mb-4 flex items-center gap-3">
        <h1 class="min-w-0 truncate text-[24px] sm:text-[34px]">{{ $car->titleWithYear() }}</h1>
        <x-ui.nav-arrows class="ml-auto shrink-0" :index="$index" :total="$total"
            :prev="$prev ? '/zakupki/'.$n.'/'.$prev->ref.'/ocenka' : null"
            :next="$next ? '/zakupki/'.$n.'/'.$next->ref.'/ocenka' : null"/>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-x-8">
        <div class="min-w-0 lg:col-start-1 lg:row-start-1"><x-offer.gallery :photos="$photos" :alt="$car->titleWithYear()" :thumbs="false"/></div>

        <div class="flex flex-wrap items-center gap-1.5 lg:col-start-1 lg:row-start-2">
            <span class="chip nums">{{ str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl }}</span>
            <span class="chip">{{ $car->kind->label() }}</span>
            @foreach ($car->facts() as $fact)<span class="chip nums">{{ $fact }}</span>@endforeach
            @if ($car->settlement?->name ?? $car->city)<span class="chip">{{ $car->settlement?->name ?? $car->city }}</span>@endif
            @if ($car->fssp)<x-ui.pill tone="urgent">Ограничения ФССП</x-ui.pill>@endif
        </div>

        <aside class="lg:col-start-2 lg:row-span-3 lg:row-start-1 lg:sticky lg:top-32 lg:self-start">
            <div class="box flex flex-col gap-4">
                @if ($car->price_revalued || $car->price_listing)
                    <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5">
                        @if ($car->price_revalued)<dt class="text-sm text-ink-dim">С учётом переоценки</dt><dd class="nums text-right font-medium">{{ number_format($car->price_revalued, 0, '', ' ') }} ₽</dd>@endif
                        @if ($car->price_listing)<dt class="text-sm text-ink-dim">Для размещения</dt><dd class="nums text-right font-medium">{{ number_format($car->price_listing, 0, '', ' ') }} ₽</dd>@endif
                    </dl>
                @endif
                @if ($live->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-1.5">@foreach ($live as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach</div>
                @endif
                <form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}/ocenka" class="flex gap-2" data-controller="bid" data-bid-asking-value="0">
                    @csrf
                    <input type="hidden" name="price_final" data-bid-target="amount" value="{{ $car->price_final }}">
                    <input type="text" inputmode="numeric" autocomplete="off" class="field-input nums !h-14 min-w-0 flex-1 text-xl" placeholder="Наша цена, ₽" aria-label="Наша цена, ₽"
                        data-bid-target="display" data-action="input->bid#input" value="{{ $car->price_final ? number_format($car->price_final, 0, '', ' ') : '' }}" data-controller="autofocus">
                    <button type="submit" class="btn btn-accent shrink-0 px-5">Дальше</button>
                </form>
                @error('price_final')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            </div>
        </aside>

        <div class="min-w-0 lg:col-start-1 lg:row-start-3">
            <h2 class="text-xl">Характеристики</h2>
            <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3">
                @foreach ($car->factsList() as $label => $value)
                    <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-medium">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($car->description)
                <h2 class="mt-8 text-xl">Описание</h2>
                <p class="mt-4 whitespace-pre-line text-ink-muted">{{ $car->description }}</p>
            @endif
            <div class="mt-8"><a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="chip">Карточка машины</a></div>
        </div>
    </div>
</x-ui.shell>
