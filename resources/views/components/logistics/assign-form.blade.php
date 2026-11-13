{{-- «Кто вывозит» (17.10.2026, владелец: «админ должен это нажать»): канал (`Channel`) и человек. Логист — регионы: он
     распределяет, исполнителя, расстояние и цену ставит сам; наши люди ПРАЙМ или другой менеджер (`Partner`, владелец: «может быть кто угодно из других менеджеров, кроме
     менеджера сделки»). Менеджера сделки здесь нет: что он забирает сам, решает «Принять» — и это уже не логистика. Одна форма в CRM (карточка «Вывоз»,
     окошко строки) и в логистике (карточка задачи). Кто именно — своё поле у каждого вида (`who[logist|manager|staff]`, сервер
     берёт поле выбранного вида: без JS скрытые панели не перебивают выбор). «Документы СДЭКом» — так же своей галкой у вида
     (`cdek[вид]`; владелец 17.10.2026: «нужно выбирать, а не всем»): у логиста (регионы) отмечена, у менеджера и наших — нет;
     у перевода парковки её нет. action — куда отправить: CRM `/offers/{n}/pickup`, логистика `/transports/{id}/executor`.
     18.10.2026 (начальник: «невозможно указать, куда везти»): группами, как Настройки iOS — «Кто вывозит», «Куда везти»
     (дальность обязательна, точка — `x-logistics.route-fields` с «Пока неизвестно») и комментарий тому, кто везёт
     (`SetRoute`). Пачкой (`route` false) — без точки и комментария: у машин они разные. В CRM у машины с держателем
     (гараж, менеджер сделки) — ещё «Иван И. сам» (20.10.2026, владелец про гараж покупателя: «он сам может забирать, а можем
     мы для него»): вернуть «забирает сам» после логистики; куда везти тогда не спрашиваем — к нему. У вывоза машины вендора
     по комиссии (Совкомбанк, 20.10.2026), пока её не везут, админу — «Аукцион»: покупатель аукциона забрал сам, это не
     назначение, а «Продана на аукционе» своей шторкой (`x-offer.auction-sale-sheet`: в логистике — рядом с шторкой «Назначить
     вывоз» карточки задачи, в CRM — у редактора и окошка).
     13.11.2026 (владелец: «над тумблером — что за машина, для кого вывоз, откуда, куда; в тумблерах — только то, что к ним
     относится»): сверху — машина (`context`, только в шторках: в окне машины CRM она и так в шапке) — название, госномер,
     коды, откуда и владелец; ниже — для кого: менеджер сделки или гаража тегом и тумблер «Забирает сам» (`self`); включён —
     «Кто вывозит» и «Куда везти» (`.assign-others`) уходят: везёт он, к себе. Менеджера среди сегментов больше нет. --}}
@props(['transport' => null, 'offer' => null, 'action', 'prefix' => 'assign', 'route' => true, 'context' => false])
@php
    use App\Logistics\Choices;
    use App\Logistics\Channel;
    $logists = Choices::logists();
    $staff = Choices::staff();
    $partners = Choices::partners($transport ?? $offer);
    // Менеджер сделки или гаража «сам» — и в логистике (13.11.2026, владелец: «почему нельзя самого менеджера выбрать, чтобы он
    // сам вывоз осуществил»); пачкой (`route` false) — нет: у машин разные менеджеры.
    $holder = $route && ($offer || $transport) && $transport?->isPickup() !== false ? Choices::managerOf($offer ?? $transport) : null;
    $self = $holder && (bool) old('self', $transport?->channel === Channel::Manager);
    $kind = old('channel', match (true) {
        $transport?->channel === Channel::Manager => count($logists) ? Channel::Logist->value : Channel::Staff->value,
        (bool) $transport?->channel => $transport->channel->value,
        default => count($logists) ? Channel::Logist->value : Channel::Staff->value,
    });
    // Кто стоит сейчас — только в поле своего вида: менеджер «сам» не подставляется логистом или исполнителем.
    $current = match ($transport?->channel) {
        Channel::Logist => $transport->logist_id,
        Channel::Manager, null => null,
        default => $transport->executor_id,
    };
    $options = array_filter([
        // Пусто — сегмент всё равно есть: «+ Новый логист / сотрудник» в шторке выбора (18.10.2026).
        Channel::Logist->value => Channel::Logist->short(),
        Channel::Staff->value => Channel::Staff->short(),
        Channel::Partner->value => count($partners) ? Channel::Partner->short() : null,
    ]);
    $pickup = ! $transport || $transport->isPickup();
    $sale = $pickup ? ($offer ?? $transport?->saleOffer()) : null;
    $auction = $sale && ! $transport?->state->picked() && \App\Offers\Actions\SellAtAuction::allows($sale, auth()->user());
    if ($auction) {
        $options[Channel::Auction->value] = Channel::Auction->short();
    }
    // Галка вида: что прислали с ошибкой, иначе как у задачи (если вид тот же), иначе — только логисту.
    $car = $transport?->car ?? $offer?->car;
    $carTitle = $transport?->title() ?? $offer?->titleWithYear();
    $from = $transport ? array_filter([$transport->city(), $transport->fromAddress()]) : array_filter([$offer?->settlement?->title(), $offer?->inspection_address]);
    $owner = $transport?->ownerName() ?? $car?->insured_name;
    $dest = $transport && $transport->channel !== Channel::Manager ? $transport->destinationLabel() : null;
    $garageOf = $holder && ($sale ?? $offer ?? $transport?->saleOffer())?->garageCar()->exists();
    $cdekOn = fn (string $k) => old("cdek.$k") !== null ? (bool) old("cdek.$k") : ($transport?->channel?->value === $k ? $transport->docs_by_cdek : $k === Channel::Logist->value);
@endphp
<form method="post" action="{{ $action }}" {{ $attributes->class(['assign-form flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    {{ $slot }}
    @if ($context && $carTitle)
        <x-ui.info class="assign-car">
            <div class="info-row">
                <x-ui.cat-icon :category="$car?->category" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1"><span class="font-medium">{{ $carTitle }}</span><x-ui.plate :value="$car?->plate"/></span>
                    <span class="mt-1.5 flex flex-wrap gap-1.5"><x-car.codes :vendor-id="$transport?->vendorId() ?? $offer?->vendor_id" :ref="$car?->claim_ref" :vin="$car?->vin"/></span>
                </span>
            </div>
            <x-ui.info-row icon="map-pin" :sub="$owner ? 'Владелец: '.$owner : null">{{ $from ? implode(', ', $from) : 'Адрес не указан' }}</x-ui.info-row>
            @if ($dest)<x-ui.info-row icon="flag">{{ $dest }}</x-ui.info-row>@endif
        </x-ui.info>
    @endif
    @if ($holder)
        <x-ui.info class="assign-self">
            {{-- Человек — аватаром, без значка рядом (владелец: «зачем иконка человечка, если у него уже есть аватарка»). --}}
            <div class="info-row items-center">
                <x-ui.avatar :user="$holder" :size="36"/>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $holder->name }}</span>
                    <span class="info-sub mt-0">{{ $garageOf ? 'Менеджер, в гараж' : 'Менеджер сделки' }}</span>
                </span>
            </div>
            <label class="info-row cursor-pointer items-center">
                <span class="min-w-0 flex-1">Забирает сам</span>
                <input type="checkbox" name="self" value="1" class="switch" @checked($self)>
            </label>
        </x-ui.info>
    @endif
    <div class="assign-others">
    @if ($options)
        <h3 class="list-head -mb-2">Кто вывозит</h3>
        <x-ui.segment name="channel" :options="$options" :value="$kind" pick/>
        <div class="contents" data-reveal-target="pane" data-reveal-key="logist" @if ($kind !== 'logist') hidden @endif>
            <div class="fields">
                <x-people.pick name="who[logist]" label="Логист" kind="logist" :people="\App\Support\People::rows(\App\Users\User::whereKey(array_keys($logists))->orderBy('name')->get())" :value="$kind === 'logist' ? ($current ?? array_key_first($logists)) : array_key_first($logists)" title="Логист"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[logist]" value="0"><x-ui.check name="cdek[logist]" :checked="$cdekOn('logist')">Документы СДЭКом</x-ui.check>@endif
        </div>
        <div class="contents" data-reveal-target="pane" data-reveal-key="staff" @if ($kind !== 'staff') hidden @endif>
            <div class="fields">
                <x-people.pick name="who[staff]" label="Исполнитель" kind="driver" :people="\App\Support\People::rows(\App\Users\User::whereKey(array_keys($staff))->orderBy('name')->get())" :value="$kind === 'staff' ? $current : null" title="Исполнитель"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[staff]" value="0"><x-ui.check name="cdek[staff]" :checked="$cdekOn('staff')">Документы СДЭКом</x-ui.check>@endif
        </div>
        <div class="contents" data-reveal-target="pane" data-reveal-key="partner" @if ($kind !== 'partner') hidden @endif>
            <div class="fields">
                <x-ui.field name="who[partner]" id="{{ $prefix }}-partner" label="Исполнитель" :options="$partners" placeholder="Выберите" :value="$kind === 'partner' ? $current : null"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[partner]" value="0"><x-ui.check name="cdek[partner]" :checked="$cdekOn('partner')">Документы СДЭКом</x-ui.check>@endif
        </div>
        @if ($auction)
            <div class="contents" data-reveal-target="pane" data-reveal-key="auction" @if ($kind !== 'auction') hidden @endif>
                <x-ui.button type="button" variant="primary" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="auction-sale-{{ $sale->number }}:open">Продана на аукционе</x-ui.button>
            </div>
        @endif
    @else
        <x-ui.empty>Пока некому: позовите логиста или сотрудника ссылкой в «Пользователях»</x-ui.empty>
    @endif
    @error('channel')<p class="field-error">{{ $message }}</p>@enderror
    @error('executor_id')<p class="field-error">{{ $message }}</p>@enderror
    @if ($options && (! $transport || $transport->isPickup()))
        <div class="assign-route contents">
        <h3 class="list-head -mb-2 mt-2">Куда везти</h3>
        <x-ui.segment name="reach" :options="\App\Logistics\Reach::options()" :value="old('reach', $transport?->reach?->value)"/>
        @error('reach')<p class="field-error -mt-2">{{ $message }}</p>@enderror
        @if ($route)
            <x-logistics.route-fields :t="$transport" :offer="$offer" :prefix="$prefix.'-route'" unknown/>
            <div class="fields"><x-ui.field name="comment" :id="$prefix.'-comment'" label="Комментарий" type="textarea" :value="old('comment', $transport?->comment)" placeholder="Например: пока неизвестно, кому везти"/></div>
        @endif
        </div>
    @endif
    </div>
    @if ($options)
        <x-ui.button type="submit" variant="primary" block class="assign-submit">{{ $transport?->channel && $transport->channel !== Channel::Manager ? 'Сохранить' : 'Назначить' }}</x-ui.button>
    @endif
</form>
