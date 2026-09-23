{{-- Дело ТС одной страницей: шапка чипами, прошедшие фазы строками с раскрытием, текущая заявка — одна форма
     (поля ТС + этап), справа на ПК факты (фото, бумаги, документы, деньги, история). Плашка — глагол этапа и «⋯».
     Письма — блоком над таймлайном (letters-card) и окном (x-mail.window); после приёма и выдачи окно открыто с черновиком вендору. --}}
@php
    use App\Park\{RequestType, RequestState, VehicleState, EventType, PhotoStage};
    use App\Park\Actions\{UndoIntake, UndoRelease, UnwindVehicle};
    use App\Support\{Money, Surface};
    $state = $vehicle->state;
    $open = $req !== null;
    $tow = $req?->isTow() ?? false;
    // Новая заявка на приём без звонка — сначала «Связались»; ?call=1 с чипа доставки — позвонить ещё раз (Contact меняет тип в обе стороны).
    $callAgain = $open && in_array($req->type, [RequestType::Intake, RequestType::Tow], true) && in_array($req->state, [RequestState::New, RequestState::Scheduled], true);
    $callForm = $open && ($req->needsCall() || ($callAgain && request()->boolean('call')));
    $intakeForm = ! $callForm && $open && (($req->type === RequestType::Intake && $state->isBefore()) || ($tow && $req->state === RequestState::InProgress));
    $releaseForm = $open && $req->type === RequestType::Release && $state === VehicleState::Stored;
    $moveForm = $open && $req->type === RequestType::Move && $state === VehicleState::Stored;
    // Нет открытой заявки: у ожидаемой — «Принять», у стоящей — «Выдать» (POST /requests заводит заявку и показывает её форму).
    $hasChain = $open && in_array($req->type, [RequestType::Intake, RequestType::Tow, RequestType::Release], true);
    $spawn = ! $hasChain ? match ($state) { VehicleState::Expected => ['intake', 'Принять'], VehicleState::Stored => ['release', 'Выдать'], default => null } : null;
    // Поля ТС сверху, пока ТС ожидается (данные из письма надо сверить); после приёма — строкой с раскрытием среди фаз.
    $vehicleOpen = $state->isBefore();
    $cur = App\Park\Timeline::current($steps);
    // Блок кадров стоит в ленте того шага, на котором их снимают, и справа на всех остальных: видны всегда.
    $shooting = match ($cur?->key) { 'intake' => PhotoStage::Intake, 'release' => PhotoStage::Release, default => null };
@endphp
<x-ui.shell :title="$vehicle->titleWithYear()" cache="no-cache">
    {{-- Шапка — только положение и люди: состояние с местом и днями, долг, «Продано», исполнитель, письма.
         Всё, что есть в полях (номер, вендор, телефон) и в «Деньгах» (ставка, начислено), тут не повторяется. --}}
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        <x-park.state :vehicle="$vehicle"/>
        @if ($debt > 0)<x-ui.pill tone="danger" :href="'/money?preset=all&car='.$vehicle->id" class="!min-h-0 !py-1 text-xs nums">долг {{ Money::rub($debt) }}</x-ui.pill>@endif
        @if ($vehicle->sold_at)
            <button type="button" class="pill pill-urgent !min-h-0 !py-1 text-xs nums" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">Продано {{ $vehicle->sold_at->translatedFormat('j M') }}</button>
            @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->pickup_name ? $vehicle->pickup_name.' ' : 'Заберёт ' }}{{ $vehicle->pickup_phone }}</a>@elseif ($vehicle->pickup_name)<span class="chip">Заберёт {{ $vehicle->pickup_name }}</span>@endif
        @endif
        {{-- Исполнитель ставится сам первым действием; чип с аватаром, нажатие — передать другому. --}}
        @if ($open && $req->assignee)
            <button type="button" class="chip person" data-action="sheet#open"><x-ui.avatar :user="$req->assignee" :size="20"/>{{ $req->assignee->shortName() }}</button>
            <x-ui.sheet id="assignee" title="Передать">
                <form method="post" action="/requests/{{ $req->id }}/assign" class="flex flex-col gap-2">
                    @csrf
                    @foreach ($staff as $u)
                        <button name="assignee_id" value="{{ $u->id }}" class="row row-check !py-2 text-left"><x-ui.avatar :user="$u" :size="32"/><span class="min-w-0 flex-1">{{ $u->name }}</span><span class="check"><input type="radio" tabindex="-1" @checked($req->assignee_id === $u->id) readonly></span></button>
                    @endforeach
                </form>
            </x-ui.sheet>
        @endif
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="actions:open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
    </div>
    @if ($errors->any())<p class="field-error -mt-3 mb-4">{{ $errors->first() }}</p>@endif

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="flex min-w-0 flex-col gap-4">
        @php
            $submit = $cur?->plate['kind'] === 'submit' ? $cur : null;
            $otherForm = $open && in_array($req->type, [RequestType::Move, RequestType::Inspection], true);
            $action = $submit ? match ($submit->key) {
                'call' => "/requests/{$submit->request->id}/contact",
                'tow' => $submit->request->state === RequestState::New ? "/requests/{$submit->request->id}/schedule" : "/requests/{$submit->request->id}/start",
                'intake' => "/requests/{$submit->request->id}/intake",
                'release' => "/requests/{$submit->request->id}/release",
                'yard' => "/cars/{$vehicle->id}/yard",
            } : null;
            $confirm = $submit?->key === 'tow' && $submit->request->state === RequestState::Scheduled ? 'Эвакуатор погрузил ТС?' : null;
        @endphp
        {{-- Одна форма на поля ТС и текущий шаг: кнопка в плашке сохраняет всё. Внутри сделанных шагов форм нет. --}}
        @if ($submit)
            <form method="post" action="{{ $action }}" id="act-form" class="flex flex-col gap-4" data-controller="vin draft reveal" data-reveal-label-selector-value="#act-submit" @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
                @csrf
                @if ($canManage)
                    <input type="hidden" name="vehicle_form" value="1">
                    @include('park.vehicles.fields-block')
                @endif
                @if ($lastLetter)@include('park.vehicles.letters-card')@endif
                @include('park.vehicles.timeline')
            </form>
        @else
            @if ($canManage && ! $state->isFinal())
                <form method="post" action="/cars/{{ $vehicle->id }}" id="vehicle-form" data-controller="vin draft">
                    @csrf @method('put')
                    @include('park.vehicles.fields-block', ['save' => true])
                </form>
            @endif
            @if ($lastLetter)@include('park.vehicles.letters-card')@endif
            @include('park.vehicles.timeline')
        @endif
        @if ($otherForm)
            <form method="post" action="/requests/{{ $req->id }}/{{ $moveForm ? 'move' : 'close' }}" id="act-form" class="flex flex-col gap-4">
                @csrf
                @unless ($moveForm)<input type="hidden" name="done" value="1">@endunless
                @if ($moveForm)
                    <x-ui.card title="Перестановка">
                        <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                            <x-ui.field name="yard_id" label="Куда" :options="$yards" :value="$req->yard_id ?? $vehicle->yard_id" required data-spots-target="yard" data-action="change->spots#sync"/>
                            <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters" :value="$vehicle->spot"/>
                            <datalist id="spots-list" data-spots-target="list"></datalist>
                        </div>
                        <div class="mt-4"><x-ui.button class="w-full sm:w-auto">Переставить</x-ui.button></div>
                    </x-ui.card>
                @elseif ($req->type === RequestType::Move)
                    <x-ui.card title="Перестановка"><span class="chip">ТС ещё не на парковке</span>@if ($req->note)<p class="mt-3 text-sm text-ink-muted">{{ $req->note }}</p>@endif</x-ui.card>
                @else
                    <x-ui.card :title="$req->type->label()">
                        @if ($req->note)<p class="mb-3 whitespace-pre-line text-sm text-ink-muted">{{ $req->note }}</p>@endif
                        <x-ui.field name="note" label="Что сделано" type="textarea"/>
                    </x-ui.card>
                @endif
                @if ($verb && ! $moveForm)<div><x-ui.button class="w-full sm:w-auto">{{ $verb }}</x-ui.button></div>@endif
            </form>
        @endif
        @foreach ($others as $r)
            <a href="/cars/{{ $vehicle->id }}?req={{ $r->id }}" class="row !py-2.5"><span class="chip bg-accent-soft text-accent-text">{{ $r->type->label() }}</span>@if ($r->planned_at)<span class="tag nums">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif<span class="text-sm text-ink-muted">ещё открыта</span></a>
        @endforeach
        {{-- «Сделано» у каждого ждущего письма своё: форма не может лежать внутри act-form. --}}
        @foreach ($asks ?? [] as $a)<form method="post" action="/cars/{{ $vehicle->id }}/letters/{{ $a->id }}/done" id="reply-form-{{ $a->id }}">@csrf</form>@endforeach
        @if ($spawn)<form method="post" action="/requests" id="spawn-form">@csrf<input type="hidden" name="type" value="{{ $spawn[0] }}"><input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}"></form>@endif

        {{-- На телефоне факты дела идут под формой; на ПК — правая колонка (ниже). --}}
    </div>

    <div class="flex min-w-0 flex-col gap-4">
        {{-- Документы — над фото: их обычно немного. Строками, «+ Документ» сверху. --}}
        <x-ui.card title="Документы" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Документ</x-ui.button></div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.papers')
        </x-ui.card>
        {{-- Кадры стадиями: от страховой, при приёме, при выдаче. Та, что сейчас снимается, стоит в ленте шага. --}}
        @foreach (PhotoStage::cases() as $one)
            @if ($one !== $shooting && $vehicle->photos()->contains(fn ($m) => PhotoStage::of($m) === $one))
                <x-park.photos :vehicle="$vehicle" :stage="$one"/>
            @endif
        @endforeach

        @if ($canManage && ($vehicle->invoices->isNotEmpty() || $pendingCharges->isNotEmpty() || $vehicle->accepted_at))
        <x-ui.card title="Деньги" data-controller="sheet">
            <div class="mb-2 flex flex-wrap gap-1.5">
                {{-- Условия по этой ТС (комиссия или хранение, ставка, ПТС/СТС, комитент) — шторка из чипа. --}}
                <button type="button" class="chip nums" data-controller="emit" data-action="emit#send" data-emit-event-param="contract:open">{{ $vehicle->contract_kind === 'commission' ? 'Комиссия'.($vehicle->assigned_price ? ' '.Money::rub($vehicle->assigned_price) : '') : ($storageRate ?: 'Условия') }}</button>
                @if ($vehicle->contract_kind === 'commission')
                    <a href="/acts/{{ $vehicle->id }}/contract" class="chip" data-turbo="false" target="_blank">Договор комиссии</a>
                    <a href="/acts/{{ $vehicle->id }}/handover" class="chip" data-turbo="false" target="_blank">Акт приёма-передачи</a>
                @endif
                @foreach ($accrued as $payer => $a)@if ($a['amount'] > 0)<a href="/cars/{{ $vehicle->id }}/invoices/new?payer={{ $payer }}" class="chip nums">не выставлено {{ Money::rub($a['amount']) }} за {{ $a['days'] }} дн{{ count($accrued) > 1 ? ' — '.\App\Billing\Accrual::payerLabel($payer) : '' }}</a>@endif @endforeach
                @if ($buyerFrom)<span class="chip nums {{ $buyerFrom->isPast() ? 'bg-danger-soft text-danger' : '' }}">покупатель с {{ $buyerFrom->translatedFormat('j M') }}, {{ Money::rub($buyerRate) }}/сут</span>@endif
                {{-- До какого дня хранение выставлено: дальше счёт считается с этого дня, и это единственное место, где видно. --}}
                @if ($vehicle->storage_billed_until)<span class="chip nums">выставлено по {{ $vehicle->storage_billed_until->translatedFormat('j M') }}</span>@endif
            </div>
            <div class="flex flex-col divide-y divide-line/40 text-sm">
                @foreach ($vehicle->invoices as $inv)
                    <a href="/money/invoices/{{ $inv->id }}" class="flex items-center gap-2 py-2"><x-billing.light :invoice="$inv"/><span class="min-w-0 flex-1 truncate">{{ $inv->isOwed() ? 'мы должны' : $inv->label() }} {{ $inv->party->name }}</span><span class="nums shrink-0 font-semibold">{{ Money::rub($inv->remaining() > 0 ? $inv->remaining() : $inv->total) }}</span></a>
                @endforeach
                @foreach ($pendingCharges as $c)
                    <form method="post" action="/cars/{{ $vehicle->id }}/charges/{{ $c->id }}" data-turbo-confirm="Снять начисление «{{ $c->title }}»?">@csrf @method('delete')
                        <button class="flex w-full items-center gap-2 py-2 text-left text-ink-muted"><span class="chip">не выставлено</span><span class="min-w-0 flex-1 truncate">{{ $c->title }}</span><span class="nums shrink-0">{{ Money::rub($c->amount) }}</span><x-ui.icon name="x" class="size-4 shrink-0"/></button>
                    </form>
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" class="btn btn-ghost btn-s" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Начислить</button>
                @if ($vehicle->accepted_at || $pendingCharges->isNotEmpty())<a href="/cars/{{ $vehicle->id }}/invoices/new" class="btn btn-ghost btn-s">Счёт</a>@endif
            </div>
            <x-ui.sheet id="charge" title="Начислить" :open="$errors->has('price')">
                <form method="post" action="/cars/{{ $vehicle->id }}/charges" class="flex flex-col gap-3" data-controller="price" data-price-prices-value="{{ json_encode($chargePrices) }}">
                    @csrf
                    <x-ui.field name="kind" label="За что" :options="$chargeKinds" data-price-target="kind" data-action="change->price#sync"/>
                    @if (count($payers) > 1)<x-ui.field name="party_id" label="Кому" :options="$payers" :value="array_key_first($payers)"/>@endif
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="qty" label="Сколько" value="1" inputmode="decimal"/>
                        <x-ui.field name="price" label="Цена, ₽" inputmode="numeric" required data-price-target="price"/>
                    </div>
                    <x-ui.field name="title" label="Как назвать в счёте"/>
                    <x-ui.button block>Начислить</x-ui.button>
                </form>
            </x-ui.sheet>
        </x-ui.card>
        @endif

        <x-ui.card title="История">
            <form method="post" action="/cars/{{ $vehicle->id }}/note" class="mb-3 flex gap-2">@csrf
                <input name="text" class="field-input flex-1" placeholder="Заметка" required>
                <x-ui.button size="sm" variant="secondary">Записать</x-ui.button>
            </form>
            {{-- Дата и время одной узкой колонкой в две строки; заметка — реплика с аватаром на подложке, событие — строкой. --}}
            <div class="flex flex-col gap-2.5 text-sm">
                @foreach ($vehicle->events as $event)
                    <div class="flex gap-3">
                        <span class="w-12 shrink-0 leading-tight text-ink-dim nums"><span class="block">{{ $event->created_at->translatedFormat('j M') }}</span><span class="block text-xs">{{ $event->created_at->format('H:i') }}</span></span>
                        @if ($event->type === EventType::Note)
                            <div class="history-note min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 text-xs text-ink-muted"><x-ui.avatar :user="$event->user" :size="18"/>{{ $event->user?->shortName() ?? 'Система' }}</div>
                                <div class="mt-1 whitespace-pre-line">{{ $event->text() }}</div>
                            </div>
                        @else
                            <div class="min-w-0 flex-1 leading-tight"><span>{{ $event->text() }}</span>@if ($event->user) <span class="text-xs text-ink-dim">{{ $event->user->shortName() }}</span>@endif</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
    </div>

    {{-- Шторки: договор, продано, действия «⋯», отмена заявки. --}}
    @if ($canManage)
        <div data-controller="sheet" data-action="contract:open@window->sheet#open" class="contents">
            <x-ui.sheet id="contract" title="Условия" :open="$errors->hasAny(['contract_kind', 'assigned_price', 'accepted_at', 'released_at', 'storage_rate'])">
                <form method="post" action="/cars/{{ $vehicle->id }}" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="contract_kind" label="Основание" :options="['storage' => 'Хранение', 'commission' => 'Договор комиссии']" :value="$vehicle->contract_kind"/>
                        <x-ui.field name="contract_no" label="Номер договора" :value="$vehicle->contract_no" :placeholder="$vehicle->ref"/>
                        <x-ui.field name="contract_at" label="Дата" type="date" :value="$vehicle->contract_at?->toDateString()"/>
                        <x-ui.field name="assigned_price" label="Назначенная цена, ₽" :value="$vehicle->assigned_price" inputmode="numeric"/>
                        <x-ui.field name="pts" label="ПТС" :value="$vehicle->pts" copy/>
                        <x-ui.field name="sts" label="СТС" :value="$vehicle->sts" copy/>
                        <x-ui.field name="owner_party_id" label="Комитент" :options="$owners" placeholder="—" :value="$vehicle->owner_party_id" span="col-span-2"/>
                        <x-ui.field name="storage_rate" label="Своя ставка, ₽/сут" :value="$vehicle->storage_rate" inputmode="numeric"/>
                        <x-ui.field name="storage_rate_note" label="Почему своя" :value="$vehicle->storage_rate_note"/>
                        <x-ui.field name="billing_cadence" label="Счёт за хранение" :options="\App\Billing\Cadence::options()" :placeholder="'Как у вендора'.($vehicle->vendor ? ' — '.mb_strtolower($vehicle->vendor->billing_cadence->label()) : '')" :value="$vehicle->billing_cadence?->value" span="col-span-2"/>
                        {{-- Даты приёма и выдачи правятся, пока хранение по ним не выставлено (сервер отобьёт иначе). --}}
                        @if ($vehicle->accepted_at)<x-ui.field name="accepted_at" label="Принята" type="datetime-local" :value="$vehicle->accepted_at->format('Y-m-d\TH:i')"/>@endif
                        @if ($vehicle->released_at)<x-ui.field name="released_at" label="Выдана" type="datetime-local" :value="$vehicle->released_at->format('Y-m-d\TH:i')"/>@endif
                    </div>
                    <x-ui.button block>Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($canManage && in_array($state, [VehicleState::Stored, VehicleState::InTransit], true) || $vehicle->sold_at)
        <div data-controller="sheet" data-action="sold:open@window->sheet#open" class="contents">
            <x-ui.sheet id="sold" title="Покупатель" :open="$errors->hasAny(['sold_at', 'pickup_name', 'pickup_phone'])">
                {{-- Страховая продала ТС: дата письма, кому выдать; дальше дни за счёт вендора и покупатель по множителю. --}}
                <form method="post" action="/cars/{{ $vehicle->id }}/sold" class="flex flex-col gap-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="sold_at" label="Дата продажи" type="date" :value="($vehicle->sold_at ?? now())->toDateString()" required/>
                        <x-ui.field name="pickup_phone" label="Телефон" type="tel" :value="$vehicle->pickup_phone"/>
                        <x-ui.field name="pickup_name" label="Кто заберёт" :value="$vehicle->pickup_name" span="col-span-2"/>
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button class="flex-1">{{ $vehicle->sold_at ? 'Сохранить' : 'Записать' }}</x-ui.button>
                        @if ($vehicle->sold_at)<x-ui.button variant="ghost" name="clear" value="1" data-turbo-confirm="Не продано?">Не продано</x-ui.button>@endif
                    </div>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    @if ($canManage && $vehicle->sold_at && $state === VehicleState::Stored && $req?->type === RequestType::Release)
        <div data-controller="sheet" data-action="refusal:open@window->sheet#open" class="contents">
            <x-ui.sheet id="refusal" title="Покупатель отказался" :open="$errors->has('reason')">
                {{-- Приехал, посмотрел и не взял: продажа снимается, ТС снова просто стоит, дальше письмо вендору. --}}
                <form method="post" action="/requests/{{ $req->id }}/refuse" class="flex flex-col gap-3">
                    @csrf
                    <x-ui.field name="reason" label="Почему отказался" type="textarea" required/>
                    <x-ui.button block>Записать отказ</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    <div data-controller="sheet" data-action="actions:open@window->sheet#open" class="contents">
        <x-ui.sheet id="vehicle-actions" title="Транспортное средство">
            <div class="flex flex-col gap-2">
                {{-- Пока ТС не принята: одна отмена — ТС и заявка исчезают, письма снова ждут в «Из писем» (в пути — эвакуация снимается). --}}
                @php $hadLetters = $vehicle->requests->contains(fn ($r) => $r->thread_id) || $letters; @endphp
                @if ($canManage && ($state->isBefore() || UnwindVehicle::allowed($vehicle)))
                    <form method="post" action="/cars/{{ $vehicle->id }}" data-turbo-confirm="{{ $hadLetters ? 'Отменить заявку? ТС исчезнет, письма вернутся в «Из писем»' : 'Отменить заявку? ТС и заявка исчезнут' }}">@csrf @method('delete')<x-ui.button variant="secondary" block>Отменить заявку</x-ui.button></form>
                @endif
                @if ($state === VehicleState::Stored)
                    <form method="post" action="/cars/{{ $vehicle->id }}/move" class="grid grid-cols-[minmax(0,1fr)_5rem] items-end gap-2">@csrf
                        <x-ui.field name="yard_id" label="Парковка" :options="$yards" :value="$vehicle->yard_id"/>
                        <x-ui.field name="spot" label="Место" :value="$vehicle->spot" list="spots-free" autocapitalize="characters"/>
                        <datalist id="spots-free">@foreach ($spots as $s)<option value="{{ $s }}">@endforeach</datalist>
                        <x-ui.button variant="secondary" class="col-span-full">Переставить</x-ui.button>
                    </form>
                    <x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="ghost" block>Перегнать на другую парковку</x-ui.button>
                @endif
                @if ($state->isBefore() && ! $open)
                    <x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="secondary" block>Забрать эвакуатором</x-ui.button>
                @endif
                @if ($canManage)
                @endif
                @if ($canManage && UndoIntake::allowed($vehicle))
                    <form method="post" action="/cars/{{ $vehicle->id }}/undo-intake" data-turbo-confirm="Отменить приём? ТС снова будет ожидаться">@csrf<x-ui.button variant="ghost" block>Принята по ошибке</x-ui.button></form>
                @endif
                @if ($canManage && UndoRelease::allowed($vehicle))
                    <form method="post" action="/cars/{{ $vehicle->id }}/undo-release" data-turbo-confirm="Отменить выдачу? ТС вернётся на парковку">@csrf<x-ui.button variant="ghost" block>Выдана по ошибке</x-ui.button></form>
                @endif
            </div>
        </x-ui.sheet>
    </div>
    <x-mail.window :url="$window" :title="$vehicle->titleWithYear()"/>
</x-ui.shell>
