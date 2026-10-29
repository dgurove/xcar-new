{{-- Машина закупки: та же карточка, что у оффера; вся карточка — ссылка. Ушедшая в предложение — кнопкой на него. --}}
@props(['car', 'purchase', 'query' => '', 'showKind' => false])
@php
    $staff = auth()->user()?->isAdmin() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $active = $staff ? $car->bids->whereIn('state', [\App\Offers\BidState::Active, \App\Offers\BidState::Accepted]) : collect();
    $href = "/purchases/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
    $main = $car->mainPhoto();
    $photos = $car->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $facts = array_filter([$car->year, $car->mileage !== null ? \App\Support\Money::nums($car->mileage).' км' : null, $car->transmission?->label(), $car->fuel?->label()]);
@endphp
<article id="car-{{ $car->id }}" class="card group">
    <x-ui.card-media :photos="$photos" :href="$href" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw"/>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="block min-w-0 flex-1 text-lg leading-snug hover:text-accent-text"><span class="line-clamp-2">{{ $car->titleWithYear() }}</span></a>
        </div>
        @if ($car->fssp)
            <div class="card-marks"><span class="mark mark-glass">Ограничения ФССП</span></div>
        @endif
    </div>
    <div class="card-extra">
        {{-- Состояние словом, тип и характеристики — строкой текста (10.10.2026: «что-то чипами, что-то текстом»). --}}
        @if (!$staff && !$mine)<x-ui.state tone="urgent">Без цены</x-ui.state>@endif
        @php $line = array_values(array_filter([$showKind ? $car->kind->label() : null, ...$facts])); @endphp
        @if ($line)<span class="nums text-sm text-ink-muted">{{ implode(', ', $line) }}</span>@endif
    </div>
    <span class="card-aside"><span class="nums text-sm font-normal text-ink-dim">{{ $car->shownNumber() }}</span></span>
    <div class="card-place">@if ($car->settlement?->title() ?? $car->city)<x-ui.place class="truncate text-sm text-ink-dim">{{ $car->settlement?->title() ?? $car->city }}</x-ui.place>@endif</div>
    <div class="card-action">
        {{-- Ссылка на предложение — только тому, кому оно уже открыто: черновик и чужая волна показа дали бы 404. --}}
        {{-- Тот же язык, что у карточки предложения: глагол «Предложить» — лаймовой капсулой .btn-get, переход и своё —
             тихой, выбранное и своя цена — лаймовым словом с галкой в тихой капсуле, «Нет предложений» — серым текстом. --}}
        @if ($car->offer && $car->offer?->isVisibleTo(auth()->user()))
            <a href="/offers/{{ $car->offer?->number }}" class="btn btn-get btn-get-quiet nums">Предложение № {{ $car->offer?->number }}</a>
        @elseif ($staff)
            @if ($best)
                @php $chosen = $best->state === \App\Offers\BidState::Accepted; @endphp
                <a href="{{ $href }}" class="btn btn-get btn-get-quiet min-w-0 shrink {{ $chosen ? 'text-accent-text' : '' }}">@if ($chosen)<x-ui.icon name="check" class="size-3.5 shrink-0"/>@endif<span class="nums">{{ \App\Support\Money::rub($best->amount) }}</span><span class="truncate font-normal opacity-80">{{ $best->user->shortName() }}{{ $active->count() > 1 ? ' +'.($active->count() - 1) : '' }}</span></a>
            @else
                <a href="{{ $href }}" class="whitespace-nowrap text-sm text-ink-dim">Нет предложений</a>
            @endif
        @elseif ($mine)
            {{-- Своя цена — состояние (лаймовым словом с галкой), а не главная кнопка: лайм у глагола «Предложить». --}}
            <a href="{{ $href }}" class="btn btn-get btn-get-quiet nums text-accent-text"><x-ui.icon name="check" class="size-3.5"/>{{ \App\Support\Money::rub($mine->amount) }}</a>
        @else
            <a href="{{ $href }}" class="btn btn-get">Предложить</a>
        @endif
    </div>
</article>
