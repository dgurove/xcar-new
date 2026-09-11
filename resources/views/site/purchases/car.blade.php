@php $qs = http_build_query(array_filter($filters, fn ($v) => $v !== null && $v !== '')); $suffix = $qs ? '?'.$qs : ''; @endphp
<x-ui.shell :title="$car->titleWithYear()" :back="'/zakupki/'.$purchase->number.$suffix" :wide="true">
    <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div class="flex flex-col gap-4">
            @if ($photos->isNotEmpty())
                <div class="relative overflow-hidden rounded-(--radius-xl) bg-surface" data-controller="gallery">
                    <div class="flex snap-x snap-mandatory overflow-x-auto" data-gallery-target="strip" style="scrollbar-width:none">
                        @foreach ($photos as $i => $media)
                            <a href="{{ \App\Media\MediaUrl::for($media, 'w1440') }}" class="aspect-[4/3] w-full shrink-0 snap-center" data-action="click->gallery#open" data-index="{{ $i }}">
                                <img src="{{ \App\Media\MediaUrl::for($media, 'w960') }}" srcset="{{ \App\Media\MediaUrl::srcset($media) }}" sizes="(min-width: 1024px) 800px, 100vw" alt="" class="size-full object-cover" @if ($i > 0) loading="lazy" @endif>
                            </a>
                        @endforeach
                    </div>
                    @if ($photos->count() > 1)<span class="absolute bottom-2 right-2 chip bg-chrome/70 text-white" data-gallery-target="counter">1 / {{ $photos->count() }}</span>@endif
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                <span class="chip">{{ str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl }}</span>
                <span class="chip">{{ $car->kind->label() }}</span>
                @if ($car->fssp)<span class="chip bg-urgent-soft text-urgent">Ограничения ФССП</span>@endif
                @if ($mine)<span class="chip bg-accent-soft text-accent-text">Ваша цена {{ number_format($mine->amount, 0, '', ' ') }} ₽</span>@endif
                @if (!$purchase->acceptsOffers())<span class="chip bg-closed-soft text-closed">Приём закрыт</span>@endif
            </div>
            <x-ui.card>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                    @foreach ([
                        'Год' => $car->year, 'Пробег' => $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null, 'VIN' => $car->vin,
                        'Коробка' => $car->transmission?->label(), 'Топливо' => $car->fuel?->label(), 'Объём' => $car->engine_volume ? number_format($car->engine_volume / 1000, 1, ',', '').' л' : null,
                        'Мощность' => $car->engine_power ? $car->engine_power.' л. с.' : null, 'Цвет' => $car->color, 'Руль' => $car->steering, 'Ключи' => $car->keys,
                        'Состояние' => $car->condition, 'Где' => $car->settlement?->name ?? $car->city ?? $car->address, 'Обременения' => $car->encumbrance,
                    ] as $label => $value)
                        @if ($value !== null && $value !== '')<div class="min-w-0"><dt class="text-ink-muted">{{ $label }}</dt><dd class="break-words tabular-nums">{{ $value }}</dd></div>@endif
                    @endforeach
                </dl>
                @if ($car->description)<p class="mt-4 whitespace-pre-line">{{ $car->description }}</p>@endif
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-4">
            <x-ui.card>
                @if ($mine?->state === \App\Purchases\OfferState::Chosen)
                    <div class="rounded-(--radius-l) bg-accent-soft p-4 text-accent-text">Ваша цена {{ number_format($mine->amount, 0, '', ' ') }} ₽ выбрана — с вами свяжутся</div>
                @elseif ($purchase->acceptsOffers())
                    <form method="post" action="/zakupki/{{ $purchase->number }}/{{ $car->ref }}/cena{{ $suffix }}" class="flex flex-col gap-3">
                        @csrf
                        <x-ui.field name="amount" :label="$mine ? 'Новая цена, ₽' : 'Ваша цена, ₽'" inputmode="numeric" :value="$mine?->amount" required/>
                        <x-ui.field name="comment" label="Комментарий" type="textarea" rows="2" :value="$mine?->comment"/>
                        <x-ui.button block>{{ $mine ? 'Изменить цену' : 'Назвать цену' }}</x-ui.button>
                    </form>
                    @if ($mine)
                        <form method="post" action="/zakupki/ceny/{{ $mine->id }}/otozvat" class="mt-2 text-center">@csrf<button class="text-sm text-ink-muted">Отозвать цену</button></form>
                    @endif
                @else
                    <p class="text-ink-muted">Приём цен закрыт</p>
                @endif
            </x-ui.card>
            <div class="flex gap-2">
                @if ($prev)<a href="/zakupki/{{ $purchase->number }}/{{ $prev->ref }}{{ $suffix }}" class="btn btn-secondary flex-1"><x-ui.icon name="chevron-left" class="size-5"/> {{ \Illuminate\Support\Str::limit($prev->title(), 14) }}</a>@endif
                @if ($next)<a href="/zakupki/{{ $purchase->number }}/{{ $next->ref }}{{ $suffix }}" class="btn btn-secondary flex-1">{{ \Illuminate\Support\Str::limit($next->title(), 14) }} <x-ui.icon name="chevron-right" class="size-5"/></a>@endif
            </div>
            @if (auth()->user()->isStaff())<a href="/admin/zakupki/{{ $purchase->number }}/{{ $car->ref }}" class="btn btn-ghost">Редактировать</a>@endif
        </div>
    </div>
</x-ui.shell>
