@php use App\Park\{VehicleState, RequestType, DocKind, DocState, ReleasedTo, InspectionKind}; use App\Support\Money; use App\Support\Surface; $photos = $vehicle->visiblePhotos(); $intake = $vehicle->lastInspection(InspectionKind::Intake); $release = $vehicle->lastInspection(InspectionKind::Release); @endphp
<x-ui.shell :title="$vehicle->titleWithYear()" :back="['ТС', '/cars']" cache="no-cache">
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        <x-park.state :vehicle="$vehicle"/>
        @if ($vehicle->ref)<span class="chip">{{ $vehicle->ref }}</span>@endif
        @if ($vehicle->category)<span class="chip">{{ $vehicle->category->label() }}</span>@endif
        @if ($vehicle->oversize)<span class="chip">Негабарит</span>@endif
        @foreach ($vehicle->flagList() as $flag)<span class="chip">{{ $flag->label() }}</span>@endforeach
        @if ($storageRate)<span class="chip nums">{{ $storageRate }}</span>@endif
        @foreach ($accrued as $payer => $a)@if ($a['amount'] > 0)<a href="/cars/{{ $vehicle->id }}/invoices/new?payer={{ $payer }}" class="chip nums">{{ \App\Support\Money::rub($a['amount']) }} за {{ $a['days'] }} дн{{ count($accrued) > 1 ? ' — '.\App\Billing\Accrual::payerLabel($payer) : '' }}</a>@endif @endforeach
        @if ($debt > 0)<x-ui.pill tone="danger" :href="'/money?preset=all&car='.$vehicle->id" class="!min-h-0 !py-1 text-xs nums">долг {{ \App\Support\Money::rub($debt) }}</x-ui.pill>@endif
        @if ($vehicle->offer)<a href="{{ Surface::Crm->url('/offers/'.$vehicle->offer->number) }}" class="chip nums" data-turbo="false">№ {{ $vehicle->offer->number }}<span class="font-normal text-ink-muted">{{ $vehicle->offer->state->label() }}</span>@if ($vehicle->offer->asking_price) {{ Money::rub($vehicle->offer->asking_price) }}@endif</a>@endif
        @if ($vehicle->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $vehicle->contact_phone) }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->contact_name ? \Illuminate\Support\Str::of($vehicle->contact_name)->explode(' ')->first().' ' : '' }}{{ $vehicle->contact_phone }}</a>@endif
        @if ($threads->count())<x-ui.pill tone="plain" :href="$threads->count() === 1 ? '/mail/'.$threads->first()->id : '/mail?preset=linked&q='.urlencode($vehicle->ref ?? '')" class="!min-h-0 !py-1 text-xs"><x-ui.icon name="mail" class="size-4"/> {{ $threads->count() === 1 ? 'Письмо' : 'Писем: '.$threads->count() }}</x-ui.pill>@endif
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
        <x-ui.sheet id="vehicle-actions" title="Транспортное средство">
            <div class="flex flex-col gap-2">
                @php $tow = $vehicle->openRequest(RequestType::Tow); $intakeReq = $vehicle->openRequest(RequestType::Intake); @endphp
                @if ($vehicle->state->isBefore())
                    @if ($tow)<x-ui.button href="/requests/{{ $tow->id }}" block>Эвакуация: {{ mb_strtolower($tow->state->label()) }}</x-ui.button>
                    @elseif ($intakeReq)<x-ui.button href="/requests/{{ $intakeReq->id }}" block>Принять на стоянку</x-ui.button>
                    @else
                        <x-ui.button href="/requests/new?type=intake&car={{ $vehicle->id }}" block>Принять на стоянку</x-ui.button>
                        @if ($vehicle->state === VehicleState::Expected)<x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="secondary" block>Забрать эвакуатором</x-ui.button>@endif
                    @endif
                @endif
                @if ($vehicle->state === VehicleState::Stored)
                    <form method="post" action="/cars/{{ $vehicle->id }}/move" class="grid grid-cols-[1fr_auto_auto] items-end gap-2">@csrf
                        <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$vehicle->yard_id"/>
                        <x-ui.field name="spot" label="Место" :value="$vehicle->spot" list="spots-free" autocapitalize="characters" class="w-24"/>
                        <datalist id="spots-free">@foreach ($spots as $s)<option value="{{ $s }}">@endforeach</datalist>
                        <x-ui.button variant="secondary">Переставить</x-ui.button>
                    </form>
                    <form method="post" action="/cars/{{ $vehicle->id }}/release" class="flex flex-col gap-2" data-turbo-confirm="Выдать ТС?">@csrf
                        <div class="flex flex-wrap gap-1.5">@foreach (ReleasedTo::cases() as $to)<label class="choice"><input type="radio" name="to" value="{{ $to->value }}"><span>{{ $to->label() }}</span></label>@endforeach</div>
                        <div class="flex items-end gap-2">
                            <x-ui.field name="released_at" label="Выдача" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" span="flex-1"/>
                            <x-ui.button variant="secondary">Выдать</x-ui.button>
                        </div>
                        @if ($debt > 0)<x-ui.check name="force">Выдать с долгом {{ \App\Support\Money::rub($debt) }}</x-ui.check>@endif
                    </form>
                    <x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="ghost" block>Перегнать на другую площадку</x-ui.button>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/intake" variant="ghost" block data-turbo="false" target="_blank">Акт приёма</x-ui.button>
                @endif
                @if ($vehicle->contract_kind === 'commission')
                    <x-ui.button href="/acts/{{ $vehicle->id }}/contract" variant="ghost" block data-turbo="false" target="_blank">Договор комиссии</x-ui.button>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/handover" variant="ghost" block data-turbo="false" target="_blank">Акт приёма-передачи</x-ui.button>
                @endif
                @if ($vehicle->state === VehicleState::Released)
                    <x-ui.button href="/acts/{{ $vehicle->id }}/intake" variant="ghost" block data-turbo="false" target="_blank">Акт приёма</x-ui.button>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/release" variant="ghost" block data-turbo="false" target="_blank">Акт выдачи</x-ui.button>
                @endif
                @if ($canManage && ($vehicle->accepted_at || $pendingCharges->isNotEmpty()))
                    <x-ui.button href="/cars/{{ $vehicle->id }}/invoices/new" variant="secondary" block>Счёт</x-ui.button>
                @endif
                @foreach ($templates as $t)
                    <x-ui.button href="/mail/new?car={{ $vehicle->id }}&template={{ $t->id }}" variant="ghost" block><x-ui.icon name="send" class="size-4"/> {{ $t->name }}</x-ui.button>
                @endforeach
                @unless ($vehicle->state->isFinal())<x-ui.button href="/requests/new?type=inspection&car={{ $vehicle->id }}" variant="ghost" block>Новая заявка</x-ui.button>@endunless
                @if ($canManage && $vehicle->state->isBefore())
                    <form method="post" action="/cars/{{ $vehicle->id }}/cancel" class="flex items-end gap-2" data-turbo-confirm="ТС не привезут?">@csrf
                        <x-ui.field name="reason" label="Почему не привезут" span="flex-1"/>
                        <x-ui.button variant="danger">Не привезена</x-ui.button>
                    </form>
                @endif
                @if ($canManage && $vehicle->state === VehicleState::Expected && $vehicle->events->count() <= 1 && $photos->isEmpty() && !$vehicle->offer_id && $threads->isEmpty())
                    <form method="post" action="/cars/{{ $vehicle->id }}" data-turbo-confirm="Удалить ТС без следов?">@csrf @method('delete')<x-ui.button variant="ghost" block>Удалить</x-ui.button></form>
                @endif
            </div>
        </x-ui.sheet>
    </div>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <form method="post" action="/cars/{{ $vehicle->id }}" id="vehicle-form" data-controller="vin draft" class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @csrf @method('put')
            <x-ui.card title="Транспортное средство" class="order-1">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <x-ui.field name="ref" label="Номер убытка" :value="$vehicle->ref" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="vendor_id" label="Заказчик" :options="$vendors" placeholder="—" :value="$vehicle->vendor_id"/>
                    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="$vehicle->brand_id" :text="$vehicle->brand?->name" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="$vehicle->model_id" :text="$vehicle->model?->name"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$vehicle->year"/>
                    <x-ui.field name="plate" label="Госномер" :value="$vehicle->plate" autocapitalize="characters"/>
                    <x-ui.vin :value="$vehicle->vin" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="color" label="Цвет" :value="$vehicle->color"/>
                    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—" :value="$vehicle->category?->value"/>
                    <x-ui.field name="value" label="Оценка, ₽" :value="$vehicle->value" inputmode="numeric"/>
                    <x-ui.field name="contact_name" label="Страхователь" :value="$vehicle->contact_name"/>
                    <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$vehicle->contact_phone"/>
                    <x-ui.check name="oversize" :checked="$vehicle->oversize" class="self-end">Негабарит</x-ui.check>
                </div>
            </x-ui.card>
            @if ($canManage)
            <x-ui.card title="Договор" class="order-1">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <x-ui.field name="contract_kind" label="Основание" :options="['storage' => 'Хранение', 'commission' => 'Договор комиссии']" :value="$vehicle->contract_kind"/>
                    <x-ui.field name="contract_no" label="Номер договора" :value="$vehicle->contract_no" :placeholder="$vehicle->ref"/>
                    <x-ui.field name="contract_at" label="Дата" type="date" :value="$vehicle->contract_at?->toDateString()"/>
                    <x-ui.field name="assigned_price" label="Назначенная цена, ₽" :value="$vehicle->assigned_price" inputmode="numeric"/>
                    <x-ui.field name="pts" label="ПТС" :value="$vehicle->pts"/>
                    <x-ui.field name="sts" label="СТС" :value="$vehicle->sts"/>
                    <x-ui.field name="owner_party_id" label="Комитент" :options="$owners" placeholder="—" :value="$vehicle->owner_party_id"/>
                    <x-ui.field name="storage_rate" label="Своя ставка, ₽/сут" :value="$vehicle->storage_rate" inputmode="numeric"/>
                    <x-ui.field name="storage_rate_note" label="Почему своя" :value="$vehicle->storage_rate_note"/>
                </div>
            </x-ui.card>
            @endif
            <x-ui.card title="Осмотр" class="order-1">
                <div class="grid grid-cols-2 gap-3">
                    <div class="field col-span-full">
                        <span class="field-label">Повреждения</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($zones as $zone)
                                <label class="choice"><input type="checkbox" switch name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', $vehicle->damage_zones ?? [])))><span>{{ $zone->label() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.field name="damage_note" label="Что ещё заметили" type="textarea" :value="$vehicle->damage_note" span="col-span-full sm:col-span-1"/>
                    <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vehicle->notes" span="col-span-full sm:col-span-1"/>
                </div>
            </x-ui.card>
        </form>
        @if ($vehicle->docs->isNotEmpty() || $vehicle->state === VehicleState::Stored)
        <x-ui.card title="Бумаги с вендором" class="order-1">
            {{-- Чип — сама бумага: серый — ещё нет, контур — отправлена, лайм — получена; нажатие открывает шторку с датой, сканом и письмом. --}}
            <div class="flex flex-wrap gap-1.5">
                @foreach ($vehicle->docs as $doc)
                    <span class="contents" data-controller="sheet">
                        <button type="button" class="chip {{ $doc->state === DocState::Received ? 'bg-accent-soft text-accent-text' : ($doc->state === DocState::Sent ? 'ring-1 ring-inset ring-accent text-accent-text' : '') }}" data-action="sheet#open">
                            @if ($doc->isDone())<x-ui.icon name="check" class="size-3.5"/>@endif
                            {{ $doc->kind->label() }}
                            @if (!$doc->isOut())<span class="font-normal">← ждём</span>@endif
                            @if ($doc->at)<span class="nums font-normal">{{ $doc->at->translatedFormat('j M') }}</span>@endif
                        </button>
                        <x-ui.sheet id="doc-{{ $doc->id }}" :title="$doc->kind->label()">
                            @include('park.vehicles.doc-form', ['doc' => $doc])
                        </x-ui.sheet>
                    </span>
                @endforeach
                <span class="contents" data-controller="sheet">
                    <button type="button" class="chip text-ink-muted" data-action="sheet#open" aria-label="Ещё бумага"><x-ui.icon name="plus" class="size-3.5"/></button>
                    <x-ui.sheet id="doc-new" title="Бумага">
                        @include('park.vehicles.doc-form', ['doc' => null])
                    </x-ui.sheet>
                </span>
            </div>
            @if ($vehicle->vendor?->intake_note)<p class="mt-3 text-sm text-ink-muted">{{ $vehicle->vendor->intake_note }}</p>@endif
        </x-ui.card>
        @endif

        <x-ui.card title="Фотографии" class="order-2 lg:col-span-2" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
            <input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.gallery')
        </x-ui.card>
        <x-ui.card title="Документы" class="order-3 lg:col-span-2" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button></div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.papers')
        </x-ui.card>

        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @if ($vehicle->requests->isNotEmpty())
            <x-ui.card title="Заявки" class="order-4">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($vehicle->requests as $r)
                        <a href="/requests/{{ $r->id }}" class="flex items-center gap-2 py-2">
                            <span class="chip {{ $r->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed' }}">{{ $r->type->label() }}</span>
                            <span class="text-sm text-ink-muted">{{ $r->isOpen() ? ($r->planned_at?->translatedFormat('j M, H:i') ?? 'ждёт') : $r->state->label() }}</span>
                            <x-ui.icon name="chevron-right" class="ml-auto size-5 text-ink-dim"/>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            @if ($intake || $release)
            <x-ui.card title="Осмотр" class="order-4">
                @foreach (array_filter([$intake, $release]) as $insp)
                    <div class="flex flex-wrap items-center gap-1.5 {{ $loop->first ? '' : 'mt-3' }}">
                        <span class="chip">{{ $insp->kind->label() }}</span>
                        @if ($insp->mileage !== null)<span class="tag nums">{{ Money::nums($insp->mileage) }} км</span>@endif
                        @if ($insp->fuel !== null)<span class="tag nums">бак {{ $insp->fuelLabel() }}</span>@endif
                        @if ($insp->keys_count !== null)<span class="tag nums">ключей {{ $insp->keys_count }}</span>@endif
                        @foreach ($insp->docs ?? [] as $d)<span class="tag">{{ \App\Park\Inspection::DOCS[$d] ?? $d }}</span>@endforeach
                        @foreach ($insp->equipment ?? [] as $e)<span class="tag">{{ \App\Park\Inspection::EQUIPMENT[$e] ?? $e }}</span>@endforeach
                        @foreach ($insp->repairMap() as $k => $v)@if ($v !== null)<span class="tag {{ $v ? 'text-danger' : '' }}">{{ \App\Park\Inspection::REPAIR[$k] }}: {{ $v ? 'ремонт' : 'цел' }}</span>@endif @endforeach
                        @if ($insp->transit_damage)<span class="tag text-danger">при перевозке: {{ $insp->transit_damage }}</span>@endif
                        @if ($insp->missing_parts)<span class="tag">нет: {{ $insp->missing_parts }}</span>@endif
                        @if ($insp->signer_name)<span class="tag">{{ $insp->signer_name }}</span>@endif
                    </div>
                @endforeach
            </x-ui.card>
            @endif
            @if ($canManage && ($vehicle->offer || $offerGuess || !$vehicle->state->isFinal()))
            <x-ui.card title="Предложение" class="order-4">
                @if ($vehicle->offer)
                    <div class="flex flex-wrap items-center gap-1.5">
                        <a href="{{ Surface::Crm->url('/offers/'.$vehicle->offer->number) }}" class="chip nums" data-turbo="false">№ {{ $vehicle->offer->number }}</a>
                        <span class="chip">{{ $vehicle->offer->state->label() }}</span>
                        @if ($vehicle->offer->car_place)<span class="chip">{{ $vehicle->offer->car_place->label() }}</span>@endif
                        <form method="post" action="/cars/{{ $vehicle->id }}/offer" class="contents" data-turbo-confirm="Снять связь с предложением?">@csrf<button class="chip text-ink-muted">снять</button></form>
                    </div>
                @else
                    <form method="post" action="/cars/{{ $vehicle->id }}/offer" class="flex items-end gap-2">@csrf
                        <x-ui.field name="number" label="№ предложения" inputmode="numeric" :value="$offerGuess?->number" span="flex-1"/>
                        <x-ui.button variant="secondary">Связать</x-ui.button>
                    </form>
                    @if ($offerGuess)<p class="mt-2 text-sm text-ink-muted">{{ $offerGuess->titleWithYear() }}{{ $offerGuess->claim_ref ? ', '.$offerGuess->claim_ref : '' }}</p>@endif
                @endif
            </x-ui.card>
            @endif

            @if ($canManage && ($vehicle->invoices->isNotEmpty() || $pendingCharges->isNotEmpty() || $vehicle->accepted_at))
            <x-ui.card title="Деньги" class="order-4" data-controller="sheet">
                <div class="flex flex-col divide-y divide-line/40 text-sm">
                    @foreach ($vehicle->invoices as $inv)
                        <a href="/money/invoices/{{ $inv->id }}" class="flex items-center gap-2 py-2"><x-billing.light :invoice="$inv"/><span class="min-w-0 flex-1 truncate">{{ $inv->isOwed() ? 'мы должны' : $inv->label() }} {{ $inv->party->name }}</span><span class="nums shrink-0 font-semibold">{{ \App\Support\Money::rub($inv->remaining() > 0 ? $inv->remaining() : $inv->total) }}</span></a>
                    @endforeach
                    @foreach ($pendingCharges as $c)
                        <form method="post" action="/cars/{{ $vehicle->id }}/charges/{{ $c->id }}" data-turbo-confirm="Снять начисление «{{ $c->title }}»?">@csrf @method('delete')
                            <button class="flex w-full items-center gap-2 py-2 text-left text-ink-muted"><span class="chip">не выставлено</span><span class="min-w-0 flex-1 truncate">{{ $c->title }}</span><span class="nums shrink-0">{{ \App\Support\Money::rub($c->amount) }}</span><x-ui.icon name="x" class="size-4 shrink-0"/></button>
                        </form>
                    @endforeach
                </div>
                <button type="button" class="btn btn-ghost btn-s mt-2" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Начислить</button>
                <x-ui.sheet id="charge" title="Начислить" :open="$errors->has('price')">
                    <form method="post" action="/cars/{{ $vehicle->id }}/charges" class="flex flex-col gap-3">
                        @csrf
                        <x-ui.field name="kind" label="За что" :options="$chargeKinds"/>
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field name="qty" label="Сколько" value="1" inputmode="decimal"/>
                            <x-ui.field name="price" label="Цена, ₽" inputmode="numeric" required/>
                        </div>
                        <x-ui.field name="title" label="Как назвать в счёте"/>
                        <x-ui.button block>Начислить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </x-ui.card>
            @endif

            <x-ui.card title="История" class="order-5">
                <form method="post" action="/cars/{{ $vehicle->id }}/note" class="mb-3 flex gap-2">@csrf
                    <input name="text" class="field-input flex-1" placeholder="Заметка" required>
                    <x-ui.button size="sm" variant="secondary">Записать</x-ui.button>
                </form>
                <div class="flex flex-col gap-2 text-sm">
                    @foreach ($vehicle->events as $event)
                        <div class="flex gap-3">
                            <span class="shrink-0 text-ink-dim">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                            <span class="min-w-0">{{ $event->text() }}</span>
                            @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    </div>
    <x-ui.action-bar><x-ui.button form="vehicle-form" class="min-w-0 flex-1">Сохранить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
