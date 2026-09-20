{{-- Дело ТС одной страницей: шапка чипами, прошедшие фазы строками с раскрытием, текущая заявка — одна форма
     (поля ТС + этап), справа на ПК факты (фото, бумаги, документы, деньги, история). Плашка — глагол этапа и «⋯».
     Письма — окном (x-mail.window): «Письма N» в шапке, после приёма и выдачи окно открыто с черновиком вендору. --}}
@php
    use App\Park\{RequestType, RequestState, VehicleState, ReleasedTo, DocState, Inspection, InspectionKind};
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
    $spawn = ! $open ? match ($state) { VehicleState::Expected => ['intake', 'Принять'], VehicleState::Stored => ['release', 'Выдать'], default => null } : null;
    $mailPhotos = $vehicle->photos()->filter(fn ($m) => ($m->getCustomProperty('stage') ?? 'mail') === 'mail');
    $intakePhotos = $vehicle->photos()->filter(fn ($m) => $m->getCustomProperty('stage') === 'intake');
    $contractChip = $vehicle->contract_kind === 'commission' ? 'Комиссия'.($vehicle->assigned_price ? ' '.Money::rub($vehicle->assigned_price) : '') : ($storageRate ?: 'Договор');
    $release = $vehicle->lastInspection(InspectionKind::Release);
    $phaseLabel = fn ($r) => match ($r->type) {
        RequestType::Intake, RequestType::Tow => $r->state === RequestState::Done ? 'Принята' : 'Приём',
        RequestType::Release => $r->state === RequestState::Done ? 'Выдана' : 'Выдача',
        RequestType::Move => $r->state === RequestState::Done ? 'Переставлена' : 'Перестановка',
        default => $r->type->label(),
    };
@endphp
<x-ui.shell :title="$vehicle->titleWithYear()" cache="no-cache">
    {{-- Шапка — один ряд чипов. --}}
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        @if ($vehicle->plate)<span class="chip nums">{{ $vehicle->plate }}</span>@endif
        <x-park.state :vehicle="$vehicle"/>
        @if ($open)
            <x-ui.pill :tone="$req->isOverdue() ? 'danger' : 'soft'" class="!min-h-0 !py-1 text-xs">{{ $req->type->label() }}@if ($req->planned_at) <span class="nums font-normal">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif</x-ui.pill>
            @if ($tow && $req->yard)<x-ui.place class="chip">→ {{ $req->yard->name }}</x-ui.place>@endif
            @if ($callAgain && !$callForm)<a href="/cars/{{ $vehicle->id }}?call=1" class="chip"><x-ui.icon name="phone" class="size-4"/> {{ $tow ? 'Эвакуатор' : ($req->delivery?->label() ?? 'Связаться') }}</a>@elseif ($req->delivery && !$tow)<span class="chip">{{ $req->delivery->label() }}</span>@endif
            @if ($req->next_call_at)<span class="chip nums {{ $req->next_call_at->isPast() ? 'text-danger' : '' }}"><x-ui.icon name="phone" class="size-4"/> {{ $req->next_call_at->translatedFormat('j M, H:i') }}</span>@endif
            @if ($tow && $req->carrier)<span class="chip">{{ $req->carrier }}</span>@endif
            @if ($tow && $req->cost)<span class="chip nums">{{ Money::rub($req->cost) }}</span>@endif
        @endif
        @if ($vehicle->ref)<span class="chip nums">{{ $vehicle->ref }}</span>@endif
        @if ($vehicle->vendor)<span class="chip">{{ $vehicle->vendor->name }}</span>@endif
        @if ($canManage)<button type="button" class="chip nums" data-controller="emit" data-action="emit#send" data-emit-event-param="contract:open">{{ $contractChip }}</button>@elseif ($storageRate)<span class="chip nums">{{ $storageRate }}</span>@endif
        @foreach ($accrued as $payer => $a)@if ($a['amount'] > 0)<a href="/cars/{{ $vehicle->id }}/invoices/new?payer={{ $payer }}" class="chip nums">{{ Money::rub($a['amount']) }} за {{ $a['days'] }} дн{{ count($accrued) > 1 ? ' — '.\App\Billing\Accrual::payerLabel($payer) : '' }}</a>@endif @endforeach
        @if ($debt > 0)<x-ui.pill tone="danger" :href="'/money?preset=all&car='.$vehicle->id" class="!min-h-0 !py-1 text-xs nums">долг {{ Money::rub($debt) }}</x-ui.pill>@endif
        @if ($vehicle->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $vehicle->contact_phone) }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->contact_name ? \Illuminate\Support\Str::of($vehicle->contact_name)->explode(' ')->first().' ' : '' }}{{ $vehicle->contact_phone }}</a>@endif
        @if ($vehicle->sold_at)
            <button type="button" class="pill pill-urgent !min-h-0 !py-1 text-xs nums" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">Продано {{ $vehicle->sold_at->translatedFormat('j M') }}</button>
            @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->pickup_name ? $vehicle->pickup_name.' ' : 'Заберёт ' }}{{ $vehicle->pickup_phone }}</a>@elseif ($vehicle->pickup_name)<span class="chip">Заберёт {{ $vehicle->pickup_name }}</span>@endif
            @if ($buyerFrom)<span class="chip nums {{ $buyerFrom->isPast() ? 'bg-danger-soft text-danger' : '' }}">покупатель с {{ $buyerFrom->translatedFormat('j M') }}, {{ Money::rub($buyerRate) }}/сут</span>@endif
        @endif
        @if ($vehicle->offer)<a href="{{ Surface::Crm->url('/offers/'.$vehicle->offer->number) }}" class="chip nums" data-turbo="false">№ {{ $vehicle->offer->number }}</a>@endif
        {{-- Исполнитель — чип с аватаром (пусто — «Беру»); нажатие — шторка: «Я» первым, строка = выбор. --}}
        @if ($open)
            <button type="button" class="chip person" data-action="sheet#open">@if ($req->assignee)<x-ui.avatar :user="$req->assignee" :size="20"/>{{ $req->assignee->shortName() }}@else<x-ui.icon name="user" class="size-4"/> Беру@endif</button>
            <x-ui.sheet id="assignee" title="Исполнитель">
                <form method="post" action="/requests/{{ $req->id }}/assign" class="flex flex-col gap-2">
                    @csrf
                    @foreach ($staff->sortBy(fn ($u) => $u->id === auth()->id() ? 0 : 1) as $u)
                        <button name="assignee_id" value="{{ $u->id }}" class="row row-check !py-2 text-left"><x-ui.avatar :user="$u" :size="32"/><span class="min-w-0 flex-1">{{ $u->id === auth()->id() ? 'Я' : $u->name }}</span><span class="check"><input type="radio" tabindex="-1" @checked($req->assignee_id === $u->id) readonly></span></button>
                    @endforeach
                    <button name="assignee_id" value="" class="row row-check !py-2 text-left"><span class="min-w-0 flex-1 text-ink-muted">Никто</span><span class="check"><input type="radio" tabindex="-1" @checked(!$req->assignee_id) readonly></span></button>
                </form>
            </x-ui.sheet>
        @endif
        @if ($letters)<x-mail.window-button :count="$letters" :url="'/cars/'.$vehicle->id.'/letters'" chip/>@endif
    </div>
    @if ($errors->any())<p class="field-error -mt-3 mb-4">{{ $errors->first() }}</p>@endif

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="flex min-w-0 flex-col gap-4">
        {{-- Прошедшие фазы — по строке, раскрываются: что заполнено, фото, подпись, акт, откат «по ошибке». --}}
        @if ($phases->isNotEmpty())
            <div class="flex flex-col gap-1.5">
                @foreach ($phases as $r)
                    @php $cancelled = $r->state === RequestState::Cancelled; $insp = $r->state === RequestState::Done && in_array($r->type, [RequestType::Intake, RequestType::Tow, RequestType::Release], true) ? $vehicle->inspections->firstWhere('request_id', $r->id) : null; $stagePhotos = $insp ? $vehicle->photos()->filter(fn ($m) => $m->getCustomProperty('stage') === ($r->type === RequestType::Release ? 'release' : 'intake')) : collect(); @endphp
                    @if ($cancelled)
                        <div class="row !py-2.5">
                            <span class="chip bg-closed-soft text-closed">{{ $r->type->label() }}, отменена</span>
                            @if ($r->done_at)<span class="tag nums">{{ $r->done_at->translatedFormat('j M, H:i') }}</span>@endif
                            @if ($r->cancel_reason)<span class="min-w-0 truncate text-sm text-ink-muted">{{ $r->cancel_reason }}</span>@endif
                        </div>
                        @continue
                    @endif
                    <details class="phase">
                        <summary class="row !py-2.5 list-none flex-wrap gap-y-1">
                            <span class="chip bg-accent-soft text-accent-text">{{ $phaseLabel($r) }}</span>
                            @if ($r->done_at)<span class="tag nums">{{ $r->done_at->translatedFormat('j M, H:i') }}</span>@endif
                            @if ($r->doneBy)<x-ui.person :user="$r->doneBy" class="tag"/>@endif
                            @if ($r->type === RequestType::Tow && $r->carrier)<span class="tag">{{ $r->carrier }}</span>@endif
                            <x-ui.icon name="chevron-down" class="phase-chevron ml-auto size-5 shrink-0 self-center text-ink-dim"/>
                        </summary>
                        <div class="phase-body flex flex-col gap-3 px-3 pb-3 pt-1">
                            @if ($r->note)<p class="whitespace-pre-line text-sm text-ink-muted">{{ $r->note }}</p>@endif
                            @if ($insp)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($insp->keys_count !== null)<span class="tag nums">ключей {{ $insp->keys_count }}</span>@endif
                                    @foreach ($insp->docs ?? [] as $d)<span class="tag">{{ Inspection::DOCS[$d] ?? $d }}</span>@endforeach
                                    @if ($insp->matches === false)<span class="tag text-danger">не соответствует{{ $insp->mismatch_note ? ': '.$insp->mismatch_note : '' }}</span>@endif
                                    @if ($insp->refused)<span class="tag text-danger">не забрал</span>@endif
                                    @if ($insp->signer_name)<span class="tag">{{ $insp->signer_name }}</span>@endif
                                    @if ($sig = $insp->signatureDataUrl())<img src="{{ $sig }}" alt="Подпись" class="h-10 rounded bg-white px-2">@endif
                                    <a href="/acts/{{ $vehicle->id }}/{{ $r->type === RequestType::Release ? 'release' : 'intake' }}" class="tag" data-turbo="false" target="_blank">Акт</a>
                                </div>
                            @endif
                            @if ($stagePhotos->isNotEmpty())
                                <div data-controller="photos" data-photos-readonly-value="true"><x-ui.photos :photos="$stagePhotos" readonly :hide="false" :main="false" id="phase-{{ $r->id }}"/></div>
                            @endif
                            @if ($canManage && in_array($r->type, [RequestType::Intake, RequestType::Tow], true) && UndoIntake::allowed($vehicle))
                                <form method="post" action="/cars/{{ $vehicle->id }}/undo-intake" class="flex items-end gap-2" data-turbo-confirm="Отменить приём? ТС снова будет ожидаться">@csrf<x-ui.field name="reason" label="Почему" span="flex-1"/><x-ui.button variant="ghost" size="sm">Принята по ошибке</x-ui.button></form>
                            @elseif ($canManage && $r->type === RequestType::Release && UndoRelease::allowed($vehicle))
                                <form method="post" action="/cars/{{ $vehicle->id }}/undo-release" class="flex items-end gap-2" data-turbo-confirm="Отменить выдачу? ТС вернётся на стоянку">@csrf<x-ui.field name="reason" label="Почему" span="flex-1"/><x-ui.button variant="ghost" size="sm">Выдана по ошибке</x-ui.button></form>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
        @foreach ($others as $r)
            <a href="/cars/{{ $vehicle->id }}?req={{ $r->id }}" class="row !py-2.5"><span class="chip bg-accent-soft text-accent-text">{{ $r->type->label() }}</span>@if ($r->planned_at)<span class="tag nums">{{ $r->planned_at->translatedFormat('j M, H:i') }}</span>@endif<span class="text-sm text-ink-muted">ещё открыта</span></a>
        @endforeach

        {{-- Текущий этап — одна форма: поля ТС и поля этапа, кнопка в плашке сохраняет всё. --}}
        @if ($open)
            @php
                $action = match (true) {
                    $callForm => "/requests/{$req->id}/contact",
                    $tow && $req->state === RequestState::New => "/requests/{$req->id}/schedule",
                    $tow && $req->state === RequestState::Scheduled => "/requests/{$req->id}/start",
                    $intakeForm => "/requests/{$req->id}/intake",
                    $moveForm => "/requests/{$req->id}/move",
                    $releaseForm => "/requests/{$req->id}/release",
                    default => "/requests/{$req->id}/close",
                };
                $confirm = $tow && $req->state === RequestState::Scheduled && !$callForm ? 'Эвакуатор погрузил ТС?' : null;
                $waiting = ! $verb;
            @endphp
            <form method="post" action="{{ $action }}" id="act-form" class="flex flex-col gap-4" data-controller="vin draft reveal" @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
                @csrf
                @if ($action === "/requests/{$req->id}/close")<input type="hidden" name="done" value="1">@endif
                @if ($canManage)
                    <input type="hidden" name="vehicle_form" value="1">
                    <x-ui.card title="Транспортное средство">
                        <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
                    </x-ui.card>
                @endif

                @if ($callForm)
                    {{-- Звонок: эвакуатор (сразу с назначением), привезёт сам (когда), не дозвонились (когда снова). --}}
                    <x-ui.card title="Звонок">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="field col-span-full">
                                <span class="field-label">Как привезут</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <label class="choice"><input type="radio" name="outcome" value="tow" data-action="reveal#pick" @checked(old('outcome', 'tow') === 'tow')><span>Эвакуатор</span></label>
                                    <label class="choice"><input type="radio" name="outcome" value="self" data-action="reveal#pick" @checked(old('outcome') === 'self')><span>Привезёт сам</span></label>
                                    <label class="choice"><input type="radio" name="outcome" value="missed" data-action="reveal#pick" @checked(old('outcome') === 'missed')><span>Не дозвонились</span></label>
                                </div>
                            </div>
                            <div class="col-span-full grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="tow">
                                <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
                                <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
                                <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
                                <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
                                <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
                                <div class="grid grid-cols-2 gap-3">
                                    <x-ui.field name="distance_km" label="Км" inputmode="numeric" :value="$req->distance_km"/>
                                    <x-ui.field name="cost" label="₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
                                </div>
                            </div>
                            <div class="col-span-full grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="self" hidden>
                                <x-ui.field name="planned_at" label="Когда привезёт" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')" disabled/>
                                <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id" disabled/>
                            </div>
                            <div class="col-span-full" data-reveal-target="pane" data-reveal-key="missed" hidden>
                                <x-ui.field name="next_call_at" label="Позвонить снова" type="datetime-local" :value="now()->addHours(2)->format('Y-m-d\TH:i')" disabled/>
                            </div>
                        </div>
                    </x-ui.card>
                @elseif ($tow && $req->state === RequestState::New)
                    {{-- Назначить: дата, откуда, куда, перевозчик, километры — стоимость из прайса, поправима. --}}
                    <x-ui.card title="Эвакуация">
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
                            <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
                            <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
                            <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
                            <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
                            <div class="grid grid-cols-2 gap-3">
                                <x-ui.field name="distance_km" label="Км" inputmode="numeric" :value="$req->distance_km"/>
                                <x-ui.field name="cost" label="₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
                            </div>
                        </div>
                    </x-ui.card>
                @elseif ($tow && $req->state === RequestState::Scheduled)
                    {{-- Назначена: «Выехали» в плашке; перенести — второй формой внутри карточки. --}}
                    <x-ui.card title="Эвакуация">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($req->planned_at)<span class="chip nums">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                            @if ($req->from_address)<x-ui.place class="chip">{{ $req->from_address }}</x-ui.place>@endif
                            @if ($req->yard)<x-ui.place class="chip">→ {{ $req->yard->name }}</x-ui.place>@endif
                            @if ($req->carrier)<span class="chip">{{ $req->carrier }}</span>@endif
                            @if ($req->distance_km)<span class="chip nums">{{ $req->distance_km }} км</span>@endif
                            @if ($req->cost)<span class="chip nums">{{ Money::rub($req->cost) }}</span>@endif
                        </div>
                    </x-ui.card>
                @elseif ($intakeForm)
                    {{-- Приём: площадка, место, когда, ключи, документы; фото по слотам рядом с кадрами из письма; подпись. Осмотра пока нет — будет модулем. --}}
                    <x-ui.card title="Приём">
                        <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                            <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$req->yard_id ?? $yards->keys()->first()" required data-spots-target="yard" data-action="change->spots#sync"/>
                            <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
                            <datalist id="spots-list" data-spots-target="list"></datalist>
                            <x-ui.field name="accepted_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
                            <div class="field">
                                <span class="field-label">Ключей</span>
                                <div class="flex gap-1.5">
                                    @for ($k = 0; $k <= 3; $k++)<label class="choice"><input type="radio" name="keys_count" value="{{ $k }}" @checked((string) old('keys_count', '1') === (string) $k)><span class="nums">{{ $k }}</span></label>@endfor
                                </div>
                            </div>
                            <div class="field col-span-full">
                                <span class="field-label">Документы</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (Inspection::DOCS as $k => $label)<label class="choice"><input type="checkbox" switch name="docs[]" value="{{ $k }}" @checked(in_array($k, old('docs', []), true))><span>{{ $label }}</span></label>@endforeach
                                </div>
                            </div>
                            @if ($storageRate)<span class="chip nums col-span-full justify-self-start">{{ $storageRate }}</span>@endif
                        </div>
                    </x-ui.card>
                    @if ($mailPhotos->isNotEmpty())
                        <x-ui.card title="Из письма" :count="$mailPhotos->count()" data-controller="photos" data-photos-readonly-value="true">
                            <x-ui.photos :photos="$mailPhotos" readonly :hide="false" :main="false" id="mail-gallery"/>
                        </x-ui.card>
                    @endif
                    <x-ui.card title="При приёме" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
                        <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                        <div hidden data-photos-target="progress" class="mb-3">
                            <div class="mb-1 text-sm text-ink-muted" data-label></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                        </div>
                        <div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" stage="intake" :slots="$slots"/></div>
                    </x-ui.card>
                    @include('park.requests.signature-fields')
                @elseif ($moveForm)
                    <x-ui.card title="Перестановка">
                        <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                            <x-ui.field name="yard_id" label="Куда" :options="$yards" :value="$req->yard_id ?? $vehicle->yard_id" required data-spots-target="yard" data-action="change->spots#sync"/>
                            <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters" :value="$vehicle->spot"/>
                            <datalist id="spots-list" data-spots-target="list"></datalist>
                        </div>
                    </x-ui.card>
                @elseif ($releaseForm)
                    <x-ui.card title="Выдача">
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.field name="released_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
                            <div class="field">
                                <span class="field-label">Кому</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (ReleasedTo::cases() as $to)
                                        <label class="choice"><input type="radio" name="to" value="{{ $to->value }}" @checked(old('to', $vehicle->sold_at ? ReleasedTo::Buyer->value : null) === $to->value)><span>{{ $to->label() }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                            @if ($vehicle->pickup_name || $vehicle->pickup_phone)<div class="col-span-full flex flex-wrap gap-1.5"><span class="tag">{{ $vehicle->pickup_name }}</span>@if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="tag nums">{{ $vehicle->pickup_phone }}</a>@endif</div>@endif
                            <x-ui.field name="note" label="По какому документу" span="col-span-full" :value="$vehicle->pickup_note"/>
                            {{-- Получатель подписывает: соответствует акту приёма или нет; не соответствует и не забрал — акт с отказом, ТС остаётся.
                                 Поле зовётся fits, не matches: имя поля формы перекрыло бы form.matches(), и Stimulus падает. --}}
                            <div class="field col-span-full">
                                <span class="field-label">Получатель осмотрел</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <label class="choice"><input type="radio" name="fits" value="1" data-action="reveal#pick" @checked(old('fits', '1') === '1')><span>Соответствует</span></label>
                                    <label class="choice"><input type="radio" name="fits" value="0" data-action="reveal#pick" @checked(old('fits') === '0')><span>Не соответствует</span></label>
                                </div>
                            </div>
                            <div class="col-span-full grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="0" hidden>
                                <x-ui.field name="mismatch_note" label="Что не так" type="textarea" span="col-span-full" disabled/>
                                <div class="field col-span-full">
                                    <div class="flex flex-wrap gap-1.5">
                                        <label class="choice"><input type="radio" name="refused" value="0" disabled @checked(old('refused', '0') === '0')><span>Забрал</span></label>
                                        <label class="choice"><input type="radio" name="refused" value="1" disabled @checked(old('refused') === '1')><span>Не забрал</span></label>
                                    </div>
                                </div>
                            </div>
                            @if ($debt > 0)
                                <div class="col-span-full flex flex-wrap items-center gap-2">
                                    @if ($debt - $buyerDebt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">{{ $buyerDebt > 0 ? 'вендор ' : 'долг ' }}{{ Money::rub($debt - $buyerDebt) }}</x-ui.pill>@endif
                                    @if ($buyerDebt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">покупатель {{ Money::rub($buyerDebt) }}</x-ui.pill><x-ui.check name="cash">Принял наличными {{ Money::rub($buyerDebt) }}</x-ui.check>@endif
                                    @if ($debtBlocks)<x-ui.check name="force">Выдать с долгом</x-ui.check>@else<span class="tag">вендору можно выдавать без оплаты</span>@endif
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                    @if ($intakePhotos->isNotEmpty())
                        <x-ui.card title="При приёме" :count="$intakePhotos->count()" data-controller="photos" data-photos-readonly-value="true">
                            <x-ui.photos :photos="$intakePhotos" readonly :hide="false" :main="false" id="intake-gallery"/>
                        </x-ui.card>
                    @endif
                    <x-ui.card title="При выдаче" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
                        <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                        <div hidden data-photos-target="progress" class="mb-3">
                            <div class="mb-1 text-sm text-ink-muted" data-label></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                        </div>
                        <div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" stage="release" :slots="$slots"/></div>
                    </x-ui.card>
                    @include('park.requests.signature-fields', ['signer' => 'Кто получил'])
                @elseif (in_array($req->type, [RequestType::Move, RequestType::Release], true))
                    <x-ui.card :title="$req->type->label()"><span class="chip">ТС ещё не на стоянке</span>@if ($req->note)<p class="mt-3 text-sm text-ink-muted">{{ $req->note }}</p>@endif</x-ui.card>
                @elseif (!$tow)
                    <x-ui.card :title="$req->type->label()">
                        @if ($req->note)<p class="mb-3 whitespace-pre-line text-sm text-ink-muted">{{ $req->note }}</p>@endif
                        <x-ui.field name="note" label="Что сделано" type="textarea"/>
                    </x-ui.card>
                @endif
            </form>
        @elseif ($canManage && ! $state->isFinal())
            {{-- Заявки нет: поля ТС правятся своей кнопкой (главная в плашке заводит следующий этап). --}}
            <form method="post" action="/cars/{{ $vehicle->id }}" id="vehicle-form" data-controller="vin draft">
                @csrf @method('put')
                <x-ui.card title="Транспортное средство">
                    <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories"/>
                    <div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить</x-ui.button></div>
                </x-ui.card>
            </form>
        @endif
        @if ($spawn)<form method="post" action="/requests" id="spawn-form">@csrf<input type="hidden" name="type" value="{{ $spawn[0] }}"><input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}"></form>@endif

        {{-- На телефоне факты дела идут под формой; на ПК — правая колонка (ниже). --}}
    </div>

    <div class="flex min-w-0 flex-col gap-4">
        @if ($vehicle->photos()->isNotEmpty() || $state === VehicleState::Stored)
        <x-ui.card title="Фотографии" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
            <input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.gallery')
        </x-ui.card>
        @endif

        @if ($vehicle->docs->isNotEmpty() || $state === VehicleState::Stored)
        <x-ui.card title="Бумаги с вендором">
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

        @if ($vehicle->papers()->isNotEmpty() || $state === VehicleState::Stored)
        <x-ui.card title="Документы" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.papers')
            <div class="mt-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Документ</x-ui.button></div>
        </x-ui.card>
        @endif

        @if ($canManage && ($vehicle->invoices->isNotEmpty() || $pendingCharges->isNotEmpty() || $vehicle->accepted_at))
        <x-ui.card title="Деньги" data-controller="sheet">
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
                <form method="post" action="/cars/{{ $vehicle->id }}/charges" class="flex flex-col gap-3">
                    @csrf
                    <x-ui.field name="kind" label="За что" :options="$chargeKinds"/>
                    @if (count($payers) > 1)<x-ui.field name="party_id" label="Кому" :options="$payers" :value="array_key_first($payers)"/>@endif
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

        <x-ui.card title="История">
            <form method="post" action="/cars/{{ $vehicle->id }}/note" class="mb-3 flex gap-2">@csrf
                <input name="text" class="field-input flex-1" placeholder="Заметка" required>
                <x-ui.button size="sm" variant="secondary">Записать</x-ui.button>
            </form>
            <div class="flex flex-col gap-2 text-sm">
                @foreach ($vehicle->events as $event)
                    <div class="flex gap-3">
                        <span class="shrink-0 text-ink-dim nums">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                        <span class="min-w-0">{{ $event->text() }}</span>
                        @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
    </div>

    {{-- Шторки: договор, продано, действия «⋯», отмена заявки. --}}
    @if ($canManage)
        <div data-controller="sheet" data-action="contract:open@window->sheet#open" class="contents">
            <x-ui.sheet id="contract" title="Договор" :open="$errors->hasAny(['contract_kind', 'assigned_price', 'accepted_at', 'released_at', 'storage_rate'])">
                <form method="post" action="/cars/{{ $vehicle->id }}" class="flex flex-col gap-3">
                    @csrf @method('put')
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="contract_kind" label="Основание" :options="['storage' => 'Хранение', 'commission' => 'Договор комиссии']" :value="$vehicle->contract_kind"/>
                        <x-ui.field name="contract_no" label="Номер договора" :value="$vehicle->contract_no" :placeholder="$vehicle->ref"/>
                        <x-ui.field name="contract_at" label="Дата" type="date" :value="$vehicle->contract_at?->toDateString()"/>
                        <x-ui.field name="assigned_price" label="Назначенная цена, ₽" :value="$vehicle->assigned_price" inputmode="numeric"/>
                        <x-ui.field name="pts" label="ПТС" :value="$vehicle->pts"/>
                        <x-ui.field name="sts" label="СТС" :value="$vehicle->sts"/>
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
            <x-ui.sheet id="sold" title="Продано" :open="$errors->hasAny(['sold_at', 'pickup_name', 'pickup_phone'])">
                {{-- Страховая продала ТС: дата письма, кому выдать; дальше дни за счёт вендора и покупатель по множителю. --}}
                <form method="post" action="/cars/{{ $vehicle->id }}/sold" class="flex flex-col gap-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="sold_at" label="Дата продажи" type="date" :value="($vehicle->sold_at ?? now())->toDateString()" required/>
                        <x-ui.field name="pickup_phone" label="Телефон" type="tel" :value="$vehicle->pickup_phone"/>
                        <x-ui.field name="pickup_name" label="Кто заберёт" :value="$vehicle->pickup_name" span="col-span-2"/>
                        <x-ui.field name="pickup_note" label="По какому документу" :value="$vehicle->pickup_note" span="col-span-2" placeholder="Доверенность, ДКП №"/>
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button class="flex-1">{{ $vehicle->sold_at ? 'Сохранить' : 'Продано' }}</x-ui.button>
                        @if ($vehicle->sold_at)<x-ui.button variant="ghost" name="clear" value="1" data-turbo-confirm="Не продано?">Не продано</x-ui.button>@endif
                    </div>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    <div data-controller="sheet" data-action="actions:open@window->sheet#open" class="contents">
        <x-ui.sheet id="vehicle-actions" title="Транспортное средство">
            <div class="flex flex-col gap-2">
                @if ($state === VehicleState::Stored)
                    <form method="post" action="/cars/{{ $vehicle->id }}/move" class="grid grid-cols-[minmax(0,1fr)_5rem] items-end gap-2">@csrf
                        <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$vehicle->yard_id"/>
                        <x-ui.field name="spot" label="Место" :value="$vehicle->spot" list="spots-free" autocapitalize="characters"/>
                        <datalist id="spots-free">@foreach ($spots as $s)<option value="{{ $s }}">@endforeach</datalist>
                        <x-ui.button variant="secondary" class="col-span-full">Переставить</x-ui.button>
                    </form>
                    @unless ($vehicle->sold_at)<x-ui.button type="button" variant="secondary" block data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">Продано</x-ui.button>@endunless
                    <x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="ghost" block>Перегнать на другую площадку</x-ui.button>
                @endif
                @if ($state->isBefore() && ! $open)
                    <x-ui.button href="/requests/new?type=tow&car={{ $vehicle->id }}" variant="secondary" block>Забрать эвакуатором</x-ui.button>
                @endif
                @if ($canManage)
                    <x-ui.button type="button" variant="ghost" block data-controller="emit" data-action="emit#send" data-emit-event-param="contract:open">Договор</x-ui.button>
                    @if ($vehicle->offer)
                        <form method="post" action="/cars/{{ $vehicle->id }}/offer" data-turbo-confirm="Снять связь с предложением?">@csrf<x-ui.button variant="ghost" block>Отвязать предложение № {{ $vehicle->offer->number }}</x-ui.button></form>
                    @elseif (! $state->isFinal())
                        <form method="post" action="/cars/{{ $vehicle->id }}/offer" class="flex items-end gap-2">@csrf
                            <x-ui.field name="number" label="№ предложения в CRM" inputmode="numeric" :value="$offerGuess?->number" span="flex-1"/>
                            <x-ui.button variant="secondary">Связать</x-ui.button>
                        </form>
                    @endif
                    @if ($vehicle->accepted_at || $pendingCharges->isNotEmpty())<x-ui.button href="/cars/{{ $vehicle->id }}/invoices/new" variant="secondary" block>Счёт</x-ui.button>@endif
                @endif
                @foreach ($templates as $t)
                    <x-ui.button type="button" variant="ghost" block data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/mail/new?car={{ $vehicle->id }}&template={{ $t->id }}"><x-ui.icon name="send" class="size-4"/> {{ $t->name }}</x-ui.button>
                @endforeach
                @if ($vehicle->accepted_at)<x-ui.button href="/acts/{{ $vehicle->id }}/intake" variant="ghost" block data-turbo="false" target="_blank">Акт приёма</x-ui.button>@endif
                @if ($release)<x-ui.button href="/acts/{{ $vehicle->id }}/release" variant="ghost" block data-turbo="false" target="_blank">{{ $release->refused ? 'Акт осмотра с отказом' : 'Акт выдачи' }}</x-ui.button>@endif
                @if ($vehicle->contract_kind === 'commission')
                    <x-ui.button href="/acts/{{ $vehicle->id }}/contract" variant="ghost" block data-turbo="false" target="_blank">Договор комиссии</x-ui.button>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/handover" variant="ghost" block data-turbo="false" target="_blank">Акт приёма-передачи</x-ui.button>
                @endif
                @unless ($state->isFinal())<x-ui.button href="/requests/new?type=inspection&car={{ $vehicle->id }}" variant="ghost" block>Осмотр</x-ui.button>@endunless
                @if ($open && $verb)<x-ui.button type="button" variant="ghost" block data-controller="emit" data-action="emit#send" data-emit-event-param="cancel:open">Отменить заявку</x-ui.button>@endif
                @if ($canManage && $state->isBefore() && ! $open)
                    <form method="post" action="/cars/{{ $vehicle->id }}/cancel" class="flex items-end gap-2" data-turbo-confirm="ТС не привезут?">@csrf
                        <x-ui.field name="reason" label="Почему не привезут" span="flex-1"/>
                        <x-ui.button variant="danger">Не привезена</x-ui.button>
                    </form>
                @endif
                {{-- Обратные ходы: заведена / принята / выдана по ошибке, «не привезена» — передумали. Причина — в ленту. --}}
                @if ($canManage && ! $open && UnwindVehicle::allowed($vehicle))
                    <form method="post" action="/cars/{{ $vehicle->id }}" data-turbo-confirm="Отменить заведение? ТС и заявка исчезнут, письмо вернётся в «Из писем»">@csrf @method('delete')<x-ui.button variant="ghost" block>Заведена по ошибке</x-ui.button></form>
                @endif
                @if ($canManage && $state === VehicleState::Cancelled)
                    <form method="post" action="/cars/{{ $vehicle->id }}/restore" data-turbo-confirm="Снова ждать ТС?">@csrf<x-ui.button variant="secondary" block>Снова ждём</x-ui.button></form>
                @endif
                @if ($canManage && UndoIntake::allowed($vehicle))
                    <form method="post" action="/cars/{{ $vehicle->id }}/undo-intake" class="flex items-end gap-2" data-turbo-confirm="Отменить приём? ТС снова будет ожидаться">@csrf
                        <x-ui.field name="reason" label="Почему" span="flex-1"/>
                        <x-ui.button variant="ghost">Принята по ошибке</x-ui.button>
                    </form>
                @endif
                @if ($canManage && UndoRelease::allowed($vehicle))
                    <form method="post" action="/cars/{{ $vehicle->id }}/undo-release" class="flex items-end gap-2" data-turbo-confirm="Отменить выдачу? ТС вернётся на стоянку">@csrf
                        <x-ui.field name="reason" label="Почему" span="flex-1"/>
                        <x-ui.button variant="ghost">Выдана по ошибке</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.sheet>
    </div>
    @if ($open && $verb)
        {{-- Отмена — не одна дверь: закрыть заявку, ТС не привезут, заведена по ошибке (письмо вернётся в «Из писем»). Причина — в ленту. --}}
        @php $onlyOpen = $vehicle->requests->filter(fn ($r) => $r->isOpen())->count() <= 1; $unwind = $onlyOpen && UnwindVehicle::allowed($vehicle) && $canManage; $notComing = $onlyOpen && $state->isBefore() && $canManage; @endphp
        <div data-controller="sheet" data-action="cancel:open@window->sheet#open" class="contents">
            <x-ui.sheet id="cancel" title="Отменить">
                <form method="post" action="/requests/{{ $req->id }}/close" id="cancel-form" class="flex flex-col gap-3">
                    @csrf<input type="hidden" name="done" value="0">
                    <x-ui.field name="note" label="Почему" type="textarea"/>
                    <x-ui.button variant="secondary" block name="exit" value="close">Закрыть заявку</x-ui.button>
                    @if ($notComing)<x-ui.button variant="danger" block name="exit" value="cancel" data-turbo-confirm="ТС не привезут?">Не привезут</x-ui.button>@endif
                    @if ($unwind)<x-ui.button variant="ghost" block name="exit" value="unwind" data-turbo-confirm="Отменить заведение? ТС и заявка исчезнут, письмо вернётся в «Из писем»">Заведена по ошибке</x-ui.button>@endif
                </form>
            </x-ui.sheet>
        </div>
    @endif

    <x-mail.window :url="$window"/>
    <x-ui.action-bar>
        @if ($open && $verb)<x-ui.button form="act-form" class="min-w-0 flex-1">{{ $verb }}</x-ui.button>
        @elseif ($spawn)<x-ui.button form="spawn-form" class="min-w-0 flex-1">{{ $spawn[1] }}</x-ui.button>@endif
        <button type="button" class="btn btn-quiet btn-round shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="actions:open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
    </x-ui.action-bar>
</x-ui.shell>
