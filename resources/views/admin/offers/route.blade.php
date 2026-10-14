{{-- Маршрут предложения — карточка на ветку («Продажа», «Вывоз»): путь по блокам, как таймлайн дела ТС (x-route.path).
     `$only` — одна ветка (дорожка редактора: «Вывоз» здесь, «Продажа» — `lane-sale` с хвостом гаража); без него — обе
     (карточка строки «Сделок»). `$tail` — шаги после маршрута в пути продажи.
     У вывоза под заголовком — кто вывозит и куда (`AssignPickup`: менеджер — к себе или к нам; логистика — её ход и
     исполнитель), кончается «Стоит у …». «Нужен вывоз», «Изменить» и «Вывоз не нужен» — в «···» у заголовка
     (шторка `x-offer.pickup-form`); выбора этапа из списка нет: вернуть назад можно только на пройденный шаг. --}}
@php
    use App\Workflow\Track;
    use App\Offers\{OfferState, Destination};
    $service = $offer->vendor?->workflow(Track::Service);
    $pickup = $offer->position(Track::Service);
    $to = $offer->pickupDestination();
    $only ??= null;
    // Гаражную машину везут всегда (06.10.2026): «В гараже» вывоз назначают, маршрута у вендора нет — заведётся сам.
    $garaged = $offer->state === OfferState::Garage && $offer->garageCar;
    $canPickup = ($service?->is_active || $garaged) && ! $pickup && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
        && ($offer->state !== OfferState::Garage || $garaged);
    $canDrop = $pickup && ! $service?->auto_start && $pickup->stage->is($service?->startStage());
    // Кто и куда правится, пока ТС не забрали: потом место — факт.
    $canEdit = $pickup && ! $offer->pickedUp();
    $positions = $offer->positions->sortBy(fn ($p) => $p->track === Track::Sale ? 0 : 1)->filter(fn ($p) => ! $only || $p->track === $only)->values();
    $withService = ! $only || $only === Track::Service;
    $sheet = $withService && ($canPickup || $canEdit);
    $pickupManagers = $sheet ? ($managers ?? \App\Users\User::withRole(\App\Users\Role::Manager)->orderBy('name')->get()) : collect();
    $openSheet = 'data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-'.$offer->number.':open"';
@endphp
@foreach ($positions as $position)
    @php $isService = $position->track === Track::Service; $more = $isService ? $canDrop || $canEdit : $canPickup && ! $only; @endphp
    <x-ui.card :title="$position->track->label()" class="{{ $cardClass ?? 'order-1' }}" :id="$isService ? 'pickup' : 'sale'">
        @if ($more)
            <x-slot:actions>
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="track-more-{{ $position->id }}"><x-ui.icon name="more" class="size-5"/></button>
                    <div id="track-more-{{ $position->id }}" class="menu" popover data-menu-target="list" role="menu">
                        @if ($isService)
                            @if ($canEdit)<button type="button" class="menu-item w-full" role="menuitem" {!! $openSheet !!}>Кто везёт</button>@endif
                            @if ($canDrop)<form method="post" action="/offers/{{ $offer->number }}/pickup" class="contents" data-turbo-confirm="Отменить вывоз?">@csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Вывоз не нужен</button></form>@endif
                        @else
                            <button type="button" class="menu-item w-full" role="menuitem" {!! $openSheet !!}>Нужен вывоз</button>
                        @endif
                    </div>
                </div>
            </x-slot:actions>
        @endif
        @if ($isService)
            <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                {{-- Везёт логистика — её ход словом и кто везёт, ссылкой на перевозку в logistics.xcar.ru (10.10.2026). --}}
                {{-- Последняя перевозка, и отменённая: логист отменил — админ видит «отменена» и назначает заново («Кто везёт»). --}}
                @php $transport = $offer->evacuator ? null : \App\Logistics\Transport::where('car_id', $offer->car_id)->latest('id')->first(); @endphp
                @if ($offer->evacuator)<x-ui.person :user="$offer->evacuator"/>
                @elseif ($transport)<span><a href="{{ \App\Support\Surface::Logistics->url('/transports/'.$transport->id) }}" class="text-accent-text" data-turbo="false">Логистика</a>, <span class="{{ $transport->state->tone() }}">{{ mb_strtolower($transport->state->label()) }}</span>{{ $transport->executorName() ? ', '.$transport->executorName() : '' }}</span>
                @else<span>Вывозим мы</span>@endif
                <span class="text-ink-muted">{{ $to === Destination::Keeper && $offer->garageCar ? 'в гараж, '.$offer->keeper()?->shortName() : mb_strtolower($to->label()) }}</span>
            </div>
        @endif
        @if ($isService && ($deal ?? null)?->isActive() && ! $deal->isGarage())
            {{-- Кто забирает у владельца и с кем говорить — внутри вывоза: это он и есть (06.10.2026). --}}
            <x-deal.handover :deal="$deal" compact lane class="mb-4"/>
        @endif
        <x-route.path :offer="$offer" :position="$position">{!! ! $isService ? ($tail ?? '') : '' !!}</x-route.path>
        @if ($errors->has('exit'))<p class="field-error mt-2">{{ $errors->first('exit') }}</p>@endif
    </x-ui.card>
@endforeach
@if ($withService && ! $pickup && $canPickup)
    <x-ui.card title="Вывоз" class="{{ $cardClass ?? 'order-1' }}" id="pickup">
        <x-ui.button type="button" size="sm" variant="secondary" data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-{{ $offer->number }}:open">Нужен вывоз</x-ui.button>
    </x-ui.card>
@endif
@if ($sheet)
    <div data-controller="sheet" data-action="pickup-{{ $offer->number }}:open@window->sheet#open" class="contents">
        <x-ui.sheet id="pickup-{{ $offer->number }}" title="Вывоз" :open="$errors->hasAny(['mode', 'evacuator_id', 'evacuation_to', 'scope', 'from_region_id', 'to_region_id', 'executor', 'executor_id', 'carrier', 'cost'])">
            <x-offer.pickup-form :offer="$offer" :managers="$pickupManagers" prefix="pickup-{{ $offer->number }}"/>
        </x-ui.sheet>
    </div>
@endif
