@php
    use App\Offers\OfferState;
    $user = auth()->user();
    $canSeePrices = $user?->role->canSeePrices();
@endphp
<x-ui.shell :title="$offer->titleWithYear()" back="/" :wide="true">
    <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div class="flex flex-col gap-4">
            <div class="relative overflow-hidden rounded-(--radius-xl) bg-surface" data-controller="gallery">
                @if ($photos->isNotEmpty())
                    <div class="flex snap-x snap-mandatory overflow-x-auto" data-gallery-target="strip" style="scrollbar-width:none">
                        @foreach ($photos as $i => $media)
                            <a href="{{ \App\Media\MediaUrl::for($media, 'w1440') }}" class="aspect-[4/3] w-full shrink-0 snap-center" data-action="click->gallery#open" data-index="{{ $i }}">
                                <x-offer.photo :media="$media" sizes="(min-width: 1024px) 60vw, 100vw" :eager="$i === 0" class="size-full object-cover"/>
                            </a>
                        @endforeach
                    </div>
                    @if ($photos->count() > 1)<span class="absolute bottom-2 right-2 chip bg-chrome/70 text-white" data-gallery-target="counter">1 / {{ $photos->count() }}</span>@endif
                @else
                    <div class="aspect-[4/3]"><x-offer.photo :media="null"/></div>
                @endif
                @auth<x-offer.favorite :offer="$offer" class="absolute right-2 top-2"/>@endauth
            </div>

            <x-ui.card>
                <div class="flex flex-wrap items-center gap-2">
                    <x-offer.state :state="$offer->state"/>
                    <span class="chip">№ {{ $offer->number }}</span>
                    @foreach ($offer->tags ?? [] as $tag)<span class="chip bg-accent-soft text-accent-text">{{ $tag }}</span>@endforeach
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                    @foreach ([
                        'Год' => $offer->year, 'Пробег' => $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null,
                        'Кузов' => $offer->body?->label(), 'Коробка' => $offer->transmission?->label(), 'Привод' => $offer->drive?->label(),
                        'Топливо' => $offer->fuel?->label(), 'Объём' => $offer->engine_volume ? $offer->engine_volume.' см³' : null,
                        'Мощность' => $offer->engine_power ? $offer->engine_power.' л. с.' : null, 'Цвет' => $offer->color,
                        'VIN' => $offer->vinMasked(), 'Причина' => $offer->damage_cause?->label(),
                        'Повреждения' => $offer->damage_zones ? implode(', ', array_map(fn ($z) => \App\Cars\DamageZone::labelOf($z), $offer->damage_zones)) : null,
                        'На ходу' => $offer->is_runnable === null ? null : ($offer->is_runnable ? 'Да' : 'Нет'),
                        'Ключи' => $offer->has_keys === null ? null : ($offer->has_keys ? 'Есть' : 'Нет'), 'Документы' => $offer->papers?->label(),
                        'Город' => $offer->settlement?->name, 'Осмотр' => $offer->inspection_address,
                    ] as $label => $value)
                        @if ($value !== null && $value !== '')<div><dt class="text-ink-muted">{{ $label }}</dt><dd class="tabular-nums">{{ $value }}</dd></div>@endif
                    @endforeach
                </dl>
                @if ($offer->description)<p class="mt-4 whitespace-pre-line">{{ $offer->description }}</p>@endif
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-4">
            <x-ui.card>
                @if ($canSeePrices)
                    <div class="flex items-baseline justify-between">
                        <span class="text-ink-muted">Цена</span>
                        <x-offer.price :amount="$offer->asking_price" class="text-2xl"/>
                    </div>
                    @if ($offer->minBid() && $offer->state === OfferState::Open)
                        <div class="mt-1 flex items-baseline justify-between text-sm"><span class="text-ink-muted">Ставка от</span><x-offer.price :amount="$offer->minBid()" muted/></div>
                    @endif
                    @if ($offer->state === OfferState::Open && $offer->bids_close_at)
                        <div class="mt-1 flex items-baseline justify-between text-sm"><span class="text-ink-muted">Приём до</span><span class="tabular-nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span></div>
                    @endif
                @endif

                @auth
                    @if ($user->role->canBid() && $offer->bidsOpen())
                        @if ($myBid)
                            <div class="mt-4 rounded-(--radius-l) bg-accent-soft p-4">
                                <div class="flex items-baseline justify-between"><span>Ваша ставка</span><x-offer.price :amount="$myBid->amount"/></div>
                                <form method="post" action="/stavki/{{ $myBid->id }}/otozvat" class="mt-2">@csrf<button class="text-sm text-ink-muted">Отозвать</button></form>
                            </div>
                        @endif
                        <form method="post" action="/offers/{{ $offer->number }}/stavka" class="mt-4 flex flex-col gap-3">
                            @csrf
                            <x-ui.field name="amount" :label="$myBid ? 'Новая ставка, ₽' : 'Ваша ставка, ₽'" inputmode="numeric" :placeholder="$offer->minBid() ? number_format($offer->minBid(), 0, '', ' ') : null" required/>
                            <x-ui.field name="comment" label="Комментарий" type="textarea" rows="2"/>
                            <x-ui.button block>{{ $myBid ? 'Поднять ставку' : 'Сделать ставку' }}</x-ui.button>
                        </form>
                    @elseif (!$user->role->canSeePrices() && $offer->state->acceptsInterest())
                        @if ($myInterest)
                            <div class="rounded-(--radius-l) bg-accent-soft p-4 text-accent-text">{{ $myInterest->state === \App\Offers\InterestState::New ? 'Менеджер свяжется с вами' : 'С вами связались' }}</div>
                        @else
                            <form method="post" action="/offers/{{ $offer->number }}/interes" class="flex flex-col gap-3">
                                @csrf
                                <x-ui.field name="comment" label="Что важно уточнить" type="textarea" rows="2"/>
                                <x-ui.button block>Узнать цену</x-ui.button>
                            </form>
                        @endif
                    @elseif ($offer->state === OfferState::Closed)
                        <p class="mt-4 text-ink-muted">Приём ставок закрыт</p>
                    @endif
                @else
                    <x-ui.button href="/vhod?intended=/offers/{{ $offer->number }}" block class="mt-2">Войти, чтобы узнать цену</x-ui.button>
                @endauth
            </x-ui.card>
            @if ($user?->isStaff())
                <a href="/admin/offers/{{ $offer->number }}" class="btn btn-secondary">Редактировать</a>
            @endif
        </div>
    </div>
</x-ui.shell>
