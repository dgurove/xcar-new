{{-- Маршрут предложения — карточка на ветку («Продажа», «Вывоз»): путь по блокам, как таймлайн дела ТС (x-route.path).
     У вывоза под заголовком — кто вывозит и куда (`AssignPickup`): на парковку вывоз кончается строкой-ссылкой на дело
     на парковке, к менеджеру и к нам — «Стоит у …». «Нужен вывоз», «Изменить» и «Вывоз не нужен» — в «···» у заголовка
     (шторка `x-offer.pickup-form`); выбора этапа из списка нет: вернуть назад можно только на пройденный шаг. --}}
@php
    use App\Workflow\Track;
    use App\Offers\{OfferState, Destination};
    $service = $offer->vendor?->workflow(Track::Service);
    $pickup = $offer->position(Track::Service);
    $pv = $offer->parkVehicle;
    $to = $offer->pickupDestination();
    $canPickup = $service?->is_active && ! $pickup && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived, OfferState::Garage], true);
    $canDrop = $pickup && ! $service?->auto_start && $pickup->stage->is($service?->startStage());
    // Кто и куда правится, пока ТС не забрали: потом место — факт.
    $canEdit = $pickup && ! in_array($pickup->stage->car_place?->value, ['keeper', 'with_us', 'ours'], true) && $offer->state !== OfferState::Garage;
    $positions = $offer->positions->sortBy(fn ($p) => $p->track === Track::Sale ? 0 : 1)->values();
    $sheet = $canPickup || $canEdit;
    $pickupManagers = $sheet ? ($managers ?? \App\Users\User::withRole(\App\Users\Role::Manager)->orderBy('name')->get()) : collect();
    $openSheet = 'data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-'.$offer->number.':open"';
@endphp
@foreach ($positions as $position)
    @php $isService = $position->track === Track::Service; $more = $isService ? $canDrop || $canEdit : $canPickup; @endphp
    <x-ui.card :title="$position->track->label()" class="order-1">
        @if ($more)
            <x-slot:actions>
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="track-more-{{ $position->id }}"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="track-more-{{ $position->id }}" class="menu" popover data-menu-target="list" role="menu">
                        @if ($isService)
                            @if ($canEdit)<button type="button" class="menu-item w-full" role="menuitem" {!! $openSheet !!}>Кто и куда вывозит</button>@endif
                            @if ($canDrop)<form method="post" action="/offers/{{ $offer->number }}/pickup" class="contents" data-turbo-confirm="Отменить вывоз?">@csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Вывоз не нужен</button></form>@endif
                        @else
                            <button type="button" class="menu-item w-full" role="menuitem" {!! $openSheet !!}>Нужен вывоз</button>
                        @endif
                    </div>
                </div>
            </x-slot:actions>
        @endif
        @if ($isService && $offer->evacuation_to)
            <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                @if ($offer->evacuator)<x-ui.person :user="$offer->evacuator"/>@else<span>Вывозим мы</span>@endif
                <span class="text-ink-muted">{{ mb_strtolower($to->label()) }}</span>
            </div>
        @endif
        <x-route.path :offer="$offer" :position="$position"/>
        @if ($isService && $pv && $to === Destination::Yard)
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
        <x-ui.button type="button" size="sm" variant="secondary" data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-{{ $offer->number }}:open">Нужен вывоз</x-ui.button>
    </x-ui.card>
@endif
@if ($sheet)
    <div data-controller="sheet" data-action="pickup-{{ $offer->number }}:open@window->sheet#open" class="contents">
        <x-ui.sheet id="pickup-{{ $offer->number }}" title="Вывоз" :open="$errors->hasAny(['evacuator_id', 'evacuation_to'])">
            <x-offer.pickup-form :offer="$offer" :managers="$pickupManagers" prefix="pickup-{{ $offer->number }}"/>
        </x-ui.sheet>
    </div>
@endif
