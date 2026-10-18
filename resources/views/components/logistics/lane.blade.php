{{-- Дорожка «Вывоз» в CRM (17.10.2026): вывоз — задача Logistics XCar, здесь — её ход лесенкой (`Transport::steps`), кто
     вывозит, ссылка в логистику. 18.10.2026: логиста и исполнителя меняют нажатием на шаг, последний шаг — «Отменить «…»»,
     история задачи свёрнута «История N». Вывоза нет — «Вывезти». В «···»: «Кто вывозит» (шторка `x-logistics.assign-form`) и «Вывоз не
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
    // Сменить — нажатием на шаг (логиста и исполнителя, кроме исполнителя логиста — его ставит логист); отменить —
    // последний сделанный шаг (`Logistics\Actions\StepBack`), как в логистике.
    $changeable = $open && ! $t->state->picked() && $t->channel !== \App\Logistics\Channel::Manager;
    $tap = fn (string $key) => $changeable && ($key === 'logist' || ($key === 'executor' && $t->channel !== \App\Logistics\Channel::Logist && $t->state !== \App\Logistics\TransportState::New));
    $undo = $t ? \App\Logistics\Actions\StepBack::last($t) : null;
    $undo = $undo && \App\Logistics\Actions\StepBack::allowed($t, auth()->user(), $undo) ? $undo : null;
    $undoKey = match ($undo) { 'called' => 'call', 'docs_sent', 'docs_received' => 'docs', default => $undo };
    $undoLabel = match ($undo) { 'logist' => 'Снять логиста', 'executor' => 'Снять исполнителя', null => null, default => 'Отменить «'.\App\Logistics\TransportEvent::STEPS[$undo].'»' };
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
            {{-- «забирает менеджер» говорит получение ниже — второй раз «забирает сам» не пишем. --}}
            @if ($t->channel !== \App\Logistics\Channel::Manager)
                <a href="{{ Surface::Logistics->url('/transports/'.$t->id) }}" class="text-accent-text" data-turbo="false">Логистика</a>
                <span class="{{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span>
            @elseif ($t->isLate())
                <span class="text-danger">не забрал в срок</span>
            @endif
            <x-car.keys :count="$keys"/>
        </div>
        {{-- Куда везти (18.10.2026): точка и дальность фразой, комментарий исполнителю — ниже; меняют в окне «Кто вывозит». --}}
        @if ($t->channel !== \App\Logistics\Channel::Manager && ($t->routeLabel() || $t->reach || $t->comment))
            <p class="mb-3 text-sm">Куда: <span @class(['text-urgent' => ! $t->routeLabel()])>{{ $t->routeLabel() ?? 'пока неизвестно' }}</span>@if ($t->reach), {{ mb_lcfirst($t->reach->label()) }}@endif</p>
            @if ($t->comment)<p class="mb-3 -mt-2 whitespace-pre-line text-sm text-ink-muted">{{ $t->comment }}</p>@endif
        @endif
        @if ($deal?->isActive() && ! $deal->isGarage())
            <x-deal.handover :deal="$deal" compact lane class="mb-4"/>
        @endif
        <div class="steps">
            @foreach ($t->steps() as $step)
                <div class="step step--{{ $step['status'] === 'current' && $t->state === \App\Logistics\TransportState::New ? 'ask' : $step['status'] }}">
                    <span class="step-dot">@if ($step['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                    <div class="step-body">
                        <div class="step-head">
                            @if ($tap($step['key']))
                                <button type="button" class="step-title flex-1 text-left underline decoration-dotted underline-offset-4" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">{{ $step['title'] }}</button>
                            @else
                                <span class="step-title flex-1">{{ $step['title'] }}</span>
                            @endif
                            @if ($step['status'] === 'done' && $step['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif
                        </div>
                        @if ($step['hint'])<p class="step-hint">{{ $step['hint'] }}</p>@endif
                        @if ($step['key'] === 'executor' && $step['status'] === 'current' && $t->state === \App\Logistics\TransportState::New)
                            <x-ui.button type="button" size="sm" class="mt-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Выбрать</x-ui.button>
                        @endif
                        @if ($step['key'] === $undoKey)
                            <form method="post" action="/offers/{{ $offer->number }}/pickup/back" class="mt-2" data-step-back="transport" data-turbo-confirm="{{ $undoLabel }}?" data-turbo-confirm-label="{{ str_starts_with($undoLabel, 'Снять') ? 'Снять' : 'Отменить' }}">
                                @csrf<button class="text-sm text-ink-muted underline decoration-dotted underline-offset-4">{{ $undoLabel }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($t->state === \App\Logistics\TransportState::Cancelled)<p class="mt-2 text-sm text-ink-muted">Вывоз отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif
        @if (($n = $t->events()->count()) > 0)
            <details class="group mt-3">
                <summary class="cursor-pointer list-none text-sm text-ink-muted group-open:mb-2">История {{ $n }}</summary>
                <x-logistics.history :t="$t" :show="8"/>
            </details>
        @endif
    @endif
    @if ($canOpen)
        <form method="post" action="/offers/{{ $offer->number }}/pickup" class="mt-2">@csrf<x-ui.button size="sm" variant="secondary">Вывезти</x-ui.button></form>
    @endif
    @if ($errors->has('pickup') || $errors->has('channel') || $errors->has('exit'))
        <p class="field-error mt-2">{{ $errors->first('pickup') ?: $errors->first('channel') ?: $errors->first('exit') }}</p>
    @endif
</x-ui.card>
@if ($open)
    <div data-controller="sheet" data-action="{{ $sheet }}:open@window->sheet#open" class="contents">
        <x-ui.sheet :id="$sheet" :title="$t->channel ? 'Вывоз' : 'Назначить вывоз'" tall>
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
