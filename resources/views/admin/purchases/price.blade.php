{{-- Оценка машины: поле «Наша цена» и «Дальше» сразу под заголовком (на iPhone полоса внизу уходит под
     клавиатуру), опорные цены и предложения менеджеров чипами, галерея, характеристики. Стрелки —
     соседи среди машин без нашей цены; «Дальше» сохраняет и ведёт к следующей без цены. --}}
@php $n = $purchase->number; $live = $car->activeOfferList()->sortByDesc('amount'); $photos = $car->visiblePhotos(); @endphp
<x-ui.shell :title="$car->titleWithYear()" :back="['Закупка', '/zakupki/'.$n.'?preset=unfinal']">
    <x-slot:actions>
        <x-ui.nav-arrows class="ml-auto sm:ml-0" :index="$index" :total="$total"
            :prev="$prev ? '/zakupki/'.$n.'/'.$prev->ref.'/ocenka' : null"
            :next="$next ? '/zakupki/'.$n.'/'.$next->ref.'/ocenka' : null"/>
    </x-slot:actions>

    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-1.5">
        <span class="chip nums">{{ str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl }}</span>
        <span class="chip">{{ $car->kind->label() }}</span>
        @if ($car->settlement?->name ?? $car->city)<span class="chip">{{ $car->settlement?->name ?? $car->city }}</span>@endif
        @if ($car->fssp)<x-ui.pill tone="urgent">Ограничения ФССП</x-ui.pill>@endif
        <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="chip">Карточка</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <aside class="lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:sticky lg:top-32 lg:self-start">
            <div class="box">
                <form method="post" action="/zakupki/{{ $n }}/{{ $car->ref }}/ocenka" class="flex gap-2" data-controller="bid" data-bid-asking-value="0">
                    @csrf
                    <input type="hidden" name="price_final" data-bid-target="amount" value="{{ $car->price_final }}">
                    <input type="text" inputmode="numeric" autocomplete="off" class="field-input nums min-w-0 flex-1 text-lg" placeholder="Наша цена, ₽" aria-label="Наша цена, ₽"
                        data-bid-target="display" data-action="input->bid#input" value="{{ $car->price_final ? number_format($car->price_final, 0, '', ' ') : '' }}" data-controller="autofocus">
                    <button type="submit" class="btn btn-accent shrink-0">Дальше</button>
                </form>
                @error('price_final')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    @if ($car->price_revalued)<span class="chip nums">переоценка {{ number_format($car->price_revalued, 0, '', ' ') }} ₽</span>@endif
                    @if ($car->price_listing)<span class="chip nums">размещение {{ number_format($car->price_listing, 0, '', ' ') }} ₽</span>@endif
                    @foreach ($live as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach
                </div>
            </div>
        </aside>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1"><x-offer.gallery :photos="$photos" :alt="$car->titleWithYear()"/></div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-2">
            @if ($car->description)
                <section class="mb-8">
                    <h2 class="text-xl">Описание</h2>
                    <p class="mt-4 whitespace-pre-line text-ink-muted">{{ $car->description }}</p>
                </section>
            @endif
            <section>
                <h2 class="text-xl">Характеристики</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3">
                    @foreach ($car->factsList() as $label => $value)
                        <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-medium">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>
        </div>
    </div>
</x-ui.shell>
