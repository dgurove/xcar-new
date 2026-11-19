{{-- Дорожка «Вывоз» в CRM (17.10.2026): вывоз — задача Logistics XCAR. 18.10.2026 (владелец: «сырая вёрстка»): рейс в логистике — пунктом «⋯» (16.11.2026);
     ниже группой строк, как Настройки iOS, — логист, исполнитель, куда везти с дальностью,
     комментарий (`x-logistics.facts`, строка нажатием открывает окно «Назначить вывоз», `x-logistics.assign-form`); под ней —
     ход лесенкой (`Transport::steps`, без шагов «кто» — они строками выше) и история свёрнутой строкой. В «···»: «Кто и
     куда», «Уже вывезли» (задним числом, `Logistics\Actions\Backdate`: ТС уже у нас или у менеджера — 20.10.2026, при «Отдать»
     ошиблись «У страховой»), отменить последний шаг (`Logistics\Actions\StepBack`), «Вывоз не нужен». Вывоза нет — «Вывезти». У сделки внутри
     — получение (контакт владельца менеджеру, `x-deal.handover`).
     compact — окно машины (12.11.2026, владелец: «информация по несколько раз дублируется»): без лесенки «Забирает … → Забрать»
     у менеджера, что забирает сам (кто он — в сведениях шапки); история — на странице. Ход логистики — целиком, как на странице
     (18.11.2026, владелец). --}}
@props(['offer', 'deal' => null, 'class' => '', 'compact' => false])
@php
    use App\Logistics\Channel;
    use App\Logistics\TransportState;
    use App\Offers\OfferState;
    use App\Support\Surface;
    $t = $offer->pickup();
    $open = $t?->isOpen();
    $canOpen = ! $t?->isOpen() && ! $offer->pickedUp() && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
        && ! $offer->onPark();
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
    {{-- Рейс в логистике — пунктом «⋯», а не лаймовой «Логистика» под заголовком «Вывоз» (16.11.2026, владелец: «выбери что-то
         одно»). Менеджер забирает сам — это не логистика, пункта нет. --}}
    @php $tripUrl = $t && ! $manager ? Surface::Logistics->url('/transports/'.$t->id) : null; @endphp
    @if ($changeable || $undo || $backdate || $tripUrl)
        <x-slot:actions>
            {{-- «⋯» вывоза — общей пилюлей со шторкой действий (`x-ui.more`, 12.11.2026). --}}
            <x-ui.more :id="'pickup-more-'.$offer->number" class="shrink-0">
                @if ($changeable || $backdate || $undo)
                    <div class="menu-group">
                        @if ($changeable)
                            <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open"><x-ui.icon name="truck" class="size-5 text-ink-muted"/>Кто и куда</button>
                        @endif
                        @if ($backdate)
                            <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}-backdate:open"><x-ui.icon name="check" class="size-5 text-ink-muted"/>Уже вывезли</button>
                        @endif
                        @if ($undo)
                            <x-ui.step-back close="" :action="'/offers/'.$offer->number.'/pickup/back'" :label="$undoLabel" kind="transport"/>
                        @endif
                    </div>
                @endif
                @if ($tripUrl)
                    <div class="menu-group">
                        <a href="{{ $tripUrl }}" class="menu-item w-full" role="menuitem" data-turbo="false"><x-ui.icon name="cat-truck" class="size-5 text-ink-muted"/>Открыть в логистике</a>
                    </div>
                @endif
                @if ($changeable)
                    <div class="menu-group">
                        <button type="button" class="menu-item w-full text-danger" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}-drop:open"><x-ui.icon name="x" class="size-5"/>Вывоз не нужен</button>
                    </div>
                @endif
            </x-ui.more>
        </x-slot:actions>
    @endif
    @if ($t)
        {{-- Не в срок и ключи — строкой, только когда есть что сказать. --}}
        @if ($t->isLate() || ($keys !== null && $keys <= 1))
            <div class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                @if ($t->isLate())<span class="text-danger">{{ $manager ? 'не забрал в срок' : 'не вывезли в срок' }}</span>@endif
                <x-car.keys :count="$keys"/>
            </div>
        @endif
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
                <x-logistics.facts lane :t="$t" :named="! $compact" :route="$routed" :logist="$changeable ? $sheet.':open' : null" :who="$changeable && $t->channel !== Channel::Logist ? $sheet.':open' : null" :where="$changeable ? $sheet.':open' : null"/>
            </div>
            @if ($routed)<div class="mb-4"><x-logistics.route :t="$t" :me="auth()->user()" readonly/></div>@endif
        @endif
        {{-- В окне — та же дорожка, что на странице (18.11.2026, владелец: «странно, что в окне иначе, надо тоже дорожкой»): шаг
             «Кто вывозит» с «Выбрать», весь ход; история — только на странице. --}}
        @unless ($told || ($compact && $manager))
        <x-logistics.steps :t="$t" ask extras="logistics.steps.lane" :with="['t' => $t, 'sheet' => $sheet]"/>
        @endunless
        @if ($t->state === TransportState::Cancelled)<p class="mt-2 text-sm text-ink-muted">Вывоз отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif
        @if ($events && ! $compact)
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
            <x-logistics.assign-form :transport="$t" :offer="$offer" :action="'/offers/'.$offer->number.'/pickup'" :prefix="$sheet" context/>
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
