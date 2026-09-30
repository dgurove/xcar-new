{{-- Маршрут предложения — карточка на ветку («Продажа», «Вывоз»): путь по блокам, как таймлайн дела ТС (x-route.path).
     Вывоз заканчивается строкой-ссылкой на дело на парковке. «Нужен вывоз» и «Вывоз не нужен» — в «···» у заголовка;
     выбора этапа из списка нет: вернуть назад можно только на пройденный шаг. --}}
@php
    use App\Workflow\Track;
    use App\Offers\OfferState;
    $service = $offer->vendor?->workflow(Track::Service);
    $pickup = $offer->position(Track::Service);
    $pv = $offer->parkVehicle;
    $canPickup = $service?->is_active && ! $pickup && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true);
    $canDrop = $pickup && ! $service?->auto_start && $pickup->stage->is($service?->startStage());
    $positions = $offer->positions->sortBy(fn ($p) => $p->track === Track::Sale ? 0 : 1)->values();
@endphp
@foreach ($positions as $position)
    @php $isService = $position->track === Track::Service; $more = $isService ? $canDrop : $canPickup; @endphp
    <x-ui.card :title="$position->track->label()" class="order-1">
        @if ($more)
            <x-slot:actions>
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="track-more-{{ $position->id }}"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="track-more-{{ $position->id }}" class="menu" popover data-menu-target="list" role="menu">
                        @if ($isService)
                            <form method="post" action="/offers/{{ $offer->number }}/pickup" class="contents" data-turbo-confirm="Отменить вывоз?">@csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Вывоз не нужен</button></form>
                        @else
                            <form method="post" action="/offers/{{ $offer->number }}/pickup" class="contents" data-turbo-confirm="Запустить вывоз автомобиля от страхователя?">@csrf<button class="menu-item w-full" role="menuitem">Нужен вывоз</button></form>
                        @endif
                    </div>
                </div>
            </x-slot:actions>
        @endif
        <x-route.path :offer="$offer" :position="$position"/>
        @if ($isService && $pv)
            {{-- ТС на парковке: где она и эвакуация словом, всё остальное — в деле на парковке. --}}
            @php $tow = $pv->openRequest(\App\Park\RequestType::Tow); @endphp
            <a href="{{ \App\Support\Surface::Park->url('/cars/'.$pv->id) }}" class="row mt-1 items-center gap-2 rounded-(--radius-m) bg-surface-2" data-turbo="false">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm text-ink-muted">Парковка</span>
                    <span class="flex flex-wrap items-center gap-1.5"><x-park.state :vehicle="$pv" only/>@if ($tow)<span class="text-sm">эвакуация {{ mb_strtolower($tow->state->label()) }}{{ $tow->planned_at ? ', '.$tow->planned_at->translatedFormat('j M') : '' }}</span>@endif @if ($pv->docsPending())<span class="text-sm text-urgent">бумаги вендору не отправлены</span>@endif</span>
                </span>
                <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
            </a>
        @endif
        @if ($errors->has('exit'))<p class="field-error mt-2">{{ $errors->first('exit') }}</p>@endif
    </x-ui.card>
@endforeach
@if ($positions->isEmpty() && $canPickup)
    <x-ui.card title="Вывоз" class="order-1">
        <form method="post" action="/offers/{{ $offer->number }}/pickup" data-turbo-confirm="Запустить вывоз автомобиля от страхователя?">@csrf<x-ui.button size="sm" variant="secondary">Нужен вывоз</x-ui.button></form>
    </x-ui.card>
@endif
