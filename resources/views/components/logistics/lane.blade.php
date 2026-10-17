{{-- Дорожка «Вывоз» в CRM (17.10.2026): вывоз — задача Logistics XCar, здесь — её ход лесенкой (`Transport::steps`), кто
     вывозит, ссылка в логистику. Вывоза нет — «Вывезти». В «···»: «Кто вывозит» (шторка `x-logistics.assign-form`) и «Вывоз не
     нужен» (с причиной). У сделки внутри — получение (контакт владельца менеджеру, `x-deal.handover`). --}}
@props(['offer', 'deal' => null, 'class' => ''])
@php
    use App\Logistics\Transport;
    use App\Offers\OfferState;
    use App\Support\Surface;
    $t = $offer->pickup();
    $open = $t?->isOpen();
    $canOpen = ! $t?->isOpen() && ! $offer->pickedUp() && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
        && $offer->parkVehicle?->state !== \App\Park\VehicleState::Stored;
    $sheet = 'pickup-'.$offer->number;
    $keys = $offer->car?->keys_count;
@endphp
@if ($t || $canOpen)
<x-ui.card title="Вывоз" :class="$class" id="pickup">
    @if ($open && ! $t->state->picked())
        <x-slot:actions>
            <div class="contents" data-controller="menu">
                <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="pickup-more-{{ $offer->number }}"><x-ui.icon name="more" class="size-5"/></button>
                <div id="pickup-more-{{ $offer->number }}" class="menu" popover data-menu-target="list" role="menu">
                    <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Кто вывозит</button>
                    <button type="button" class="menu-item w-full text-danger" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}-drop:open">Вывоз не нужен</button>
                </div>
            </div>
        </x-slot:actions>
    @endif
    @if ($t)
        <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            {{-- Менеджер забирает сам — это не логистика (17.10.2026): ссылки туда нет, «Забрал» он жмёт в своей сделке. --}}
            @if ($t->executor !== \App\Logistics\Executor::Manager)<a href="{{ Surface::Logistics->url('/transports/'.$t->id) }}" class="text-accent-text" data-turbo="false">Логистика</a>@endif
            <span class="{{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span>
            <x-car.keys :count="$keys"/>
        </div>
        @if ($deal?->isActive() && ! $deal->isGarage())
            <x-deal.handover :deal="$deal" compact lane class="mb-4"/>
        @endif
        <div class="steps">
            @foreach ($t->steps() as $step)
                <div class="step step--{{ $step['status'] === 'current' && $t->state === \App\Logistics\TransportState::New ? 'ask' : $step['status'] }}">
                    <span class="step-dot">@if ($step['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                    <div class="step-body">
                        <div class="step-head">
                            <span class="step-title flex-1">{{ $step['title'] }}</span>
                            @if ($step['status'] === 'done' && $step['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif
                        </div>
                        @if ($step['hint'])<p class="step-hint">{{ $step['hint'] }}</p>@endif
                        @if ($step['key'] === 'executor' && $step['status'] === 'current')
                            <x-ui.button type="button" size="sm" class="mt-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Выбрать</x-ui.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($t->state === \App\Logistics\TransportState::Cancelled)<p class="mt-2 text-sm text-ink-muted">Вывоз отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif
    @endif
    @if ($canOpen)
        <form method="post" action="/offers/{{ $offer->number }}/pickup" class="mt-2">@csrf<x-ui.button size="sm" variant="secondary">Вывезти</x-ui.button></form>
    @endif
    @if ($errors->has('pickup') || $errors->has('executor') || $errors->has('exit'))
        <p class="field-error mt-2">{{ $errors->first('pickup') ?: $errors->first('executor') ?: $errors->first('exit') }}</p>
    @endif
</x-ui.card>
@if ($open)
    <div data-controller="sheet" data-action="{{ $sheet }}:open@window->sheet#open" class="contents">
        <x-ui.sheet :id="$sheet" title="Кто вывозит">
            <x-logistics.assign-form :transport="$t" :offer="$offer" :action="'/offers/'.$offer->number.'/pickup'" :prefix="$sheet"/>
        </x-ui.sheet>
    </div>
    <div data-controller="sheet" data-action="{{ $sheet }}-drop:open@window->sheet#open" class="contents">
        <x-ui.sheet :id="$sheet.'-drop'" title="Вывоз не нужен">
            <form method="post" action="/offers/{{ $offer->number }}/pickup" class="flex flex-col gap-4">
                @csrf @method('delete')
                <div class="fields"><x-ui.field name="reason" id="{{ $sheet }}-reason" label="Почему" type="textarea"/></div>
                <x-ui.sheet-foot type="submit" variant="danger">Отменить вывоз</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    </div>
@endif
@endif
