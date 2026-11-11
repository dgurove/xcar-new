{{-- Дорожка «Вывоз» в CRM (17.10.2026): вывоз — задача Logistics XCar. 18.10.2026 (владелец: «сырая вёрстка»): сверху —
     ссылка в логистику и что дальше; ниже группой строк, как Настройки iOS, — логист, исполнитель, куда везти с дальностью,
     комментарий (`x-logistics.facts`, строка нажатием открывает окно «Назначить вывоз», `x-logistics.assign-form`); под ней —
     ход лесенкой (`Transport::steps`, без шагов «кто» — они строками выше) и история свёрнутой строкой. В «···»: «Кто и
     куда», «Уже вывезли» (задним числом, `Logistics\Actions\Backdate`: ТС уже у нас или у менеджера — 20.10.2026, при «Отдать»
     ошиблись «У страховой»), отменить последний шаг (`Logistics\Actions\StepBack`), «Вывоз не нужен». Вывоза нет — «Вывезти». У сделки внутри
     — получение (контакт владельца менеджеру, `x-deal.handover`). --}}
@props(['offer', 'deal' => null, 'class' => ''])
@php
    use App\Logistics\Channel;
    use App\Logistics\TransportState;
    use App\Offers\OfferState;
    use App\Support\Surface;
    $t = $offer->pickup();
    $open = $t?->isOpen();
    $canOpen = ! $t?->isOpen() && ! $offer->pickedUp() && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
        && $offer->parkVehicle?->state !== \App\Park\VehicleState::Stored;
    $sheet = 'pickup-'.$offer->number;
    $keys = $offer->car?->keys_count;
    $manager = (bool) $t?->isSelfPickup();
    // Кто и куда меняются окном «Назначить вывоз», пока ТС не забрали; исполнителя логиста ставит логист. Забирает менеджер
    // сделки — тоже: 19.10.2026 (ГАЗель 807360) такой вывоз нельзя было передать в логистику — окна не было ни тут, ни там.
    $changeable = $open && ! $t->taken();
    $undo = $t ? \App\Logistics\Actions\StepBack::last($t) : null;
    $undo = $undo && \App\Logistics\Actions\StepBack::allowed($t, auth()->user(), $undo) ? $undo : null;
    $undoLabel = \App\Logistics\Actions\StepBack::label($undo);
    $backdate = $open && auth()->user()->leadsLogistics();
    $events = $t ? $t->events()->count() : 0;
@endphp
@if ($t || $canOpen)
<x-ui.card title="Вывоз" :class="$class" id="pickup">
    @if ($changeable || $undo || $backdate)
        <x-slot:actions>
            <div class="contents" data-controller="menu">
                <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="pickup-more-{{ $offer->number }}"><x-ui.icon name="more" class="size-5"/></button>
                <div id="pickup-more-{{ $offer->number }}" class="menu" popover data-menu-target="list" data-align="end" role="menu">
                    @if ($changeable)
                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="{{ $sheet }}:open"><x-ui.icon name="truck" class="size-5 text-ink-muted"/>Кто и куда</button>
                    @endif
                    @if ($backdate)
                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="{{ $sheet }}-backdate:open"><x-ui.icon name="check" class="size-5 text-ink-muted"/>Уже вывезли</button>
                    @endif
                    @if ($undo)
                        <x-ui.step-back :action="'/offers/'.$offer->number.'/pickup/back'" :label="$undoLabel" kind="transport"/>
                    @endif
                    @if ($changeable)
                        <button type="button" class="menu-item w-full text-danger" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="{{ $sheet }}-drop:open"><x-ui.icon name="x" class="size-5"/>Вывоз не нужен</button>
                    @endif
                </div>
            </div>
        </x-slot:actions>
    @endif
    @if ($t)
        <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            {{-- Менеджер забирает сам — это не логистика (17.10.2026): ссылки туда нет, «Забрал» он жмёт в своей сделке. --}}
            @if (! $manager)
                <a href="{{ Surface::Logistics->url('/transports/'.$t->id) }}" class="text-accent-text" data-turbo="false">Логистика</a>
                <span class="{{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span>
            @elseif ($t->isLate())
                <span class="text-danger">не забрал в срок</span>
            @endif
            <x-car.keys :count="$keys"/>
        </div>
        @if ($deal?->isActive() && ! $deal->isGarage())
            <x-deal.handover :deal="$deal" compact lane class="mb-4"/>
        @endif
        @php
            // Менеджер забирает сам, а получение выше уже говорит «Заберёт …, после оплаты» (03.11.2026, владелец: в окошке «Вывоз»
            // трижды одно и то же) — здесь только действие и без лесенки «Забирает → Забрать».
            $told = $manager && $deal?->isActive() && ! $deal->isGarage();
        @endphp
        @if ($manager && $changeable)
            {{-- Менеджер забирает сам — одной строкой; нажатие — то же окно «Назначить вывоз», где его меняют на логиста или наших. --}}
            <div class="list mb-4">
                <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">
                    @if ($told)
                        <span class="min-w-0 flex-1 text-accent-text">Передать в логистику</span><x-ui.chevron/>
                    @else
                        {{-- Действие — второй строкой: справа рядом с именем в узкой колонке оно ломало строку по словам. --}}
                        <span class="min-w-0 flex-1"><span class="block">Забирает сам</span><span class="block text-sm text-accent-text">Передать в логистику</span></span><x-ui.chevron/>
                    @endif
                </button>
            </div>
        @endif
        @if (! $manager && $t->channel !== Channel::Auction)
            {{-- Маршрут через стоянки (20.10.2026) — только смотреть: стоянки и исполнителей отрезков ставят в логистике. --}}
            @php $routed = $t->channel === Channel::Logist && $t->logist && $t->chain()->count() > 1; @endphp
            <div class="list mb-4">
                <x-logistics.facts :t="$t" :route="$routed" :logist="$changeable ? $sheet.':open' : null" :who="$changeable && $t->channel !== Channel::Logist ? $sheet.':open' : null" :where="$changeable ? $sheet.':open' : null"/>
            </div>
            @if ($routed)<div class="mb-4"><x-logistics.route :t="$t" :me="auth()->user()" readonly/></div>@endif
        @endif
        @unless ($told)
        <x-logistics.steps :t="$t" ask extras="logistics.steps.lane" :with="['t' => $t, 'sheet' => $sheet]"/>
        @endunless
        @if ($t->state === TransportState::Cancelled)<p class="mt-2 text-sm text-ink-muted">Вывоз отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif
        @if ($events)
            <details class="group list mt-4">
                <summary class="row cursor-pointer list-none items-center justify-between gap-4">
                    <span>История вывоза <span class="nums text-ink-dim">{{ $events }}</span></span>
                    <x-ui.chevron class="transition-transform group-open:rotate-90"/>
                </summary>
                <div class="row"><x-logistics.history :t="$t" :me="auth()->user()" :show="8" class="w-full"/></div>
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
    @if ($backdate)
        <div data-controller="sheet" data-action="{{ $sheet }}-backdate:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="$sheet.'-backdate'" title="Уже вывезли" tall>
                <x-logistics.backdate-form :transport="$t" :action="'/offers/'.$offer->number.'/pickup/backdate'" :prefix="$sheet.'-backdate'"/>
            </x-ui.sheet>
        </div>
    @endif
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
