@php
    use App\Park\{RequestType, RequestState, VehicleState, Inspection, ReleasedTo};
    use App\Support\Money;
    $open = $req->isOpen();
    $tow = $req->isTow();
    // Новая заявка на приём без звонка — сначала «Связались»: эвакуатор или сам.
    // Позвонить ещё раз можно и после первого решения (?call=1 с чипа доставки): Contact меняет тип в обе стороны.
    $callAgain = $open && in_array($req->type, [RequestType::Intake, RequestType::Tow], true) && in_array($req->state, [RequestState::New, RequestState::Scheduled], true);
    $callForm = $open && ($req->needsCall() || ($callAgain && request()->boolean('call')));
    $verb = $req->verb();
    $intakeForm = ! $callForm && $open && (($req->type === RequestType::Intake && $vehicle->state->isBefore()) || ($tow && $req->state === RequestState::InProgress));
    $contactName = $req->contact_name ?? $vehicle->contact_name;
    $contactPhone = $req->contact_phone ?? $vehicle->contact_phone;
@endphp
{{-- Заявка одной страницей: ТС правится тут же компактной карточкой, звонок / эвакуация / приём / выдача — ниже,
     письма — окном (x-mail.window), кадры из письма — рядом со слотами приёма, чтобы сравнивать. --}}
<x-ui.shell :title="$req->type->label()" :back="['Заявки', '/']">
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="flex min-w-0 flex-col gap-4" data-controller="sheet">
        <x-park.vehicle-row :vehicle="$vehicle" :href="'/cars/'.$vehicle->id">
            <x-park.state :vehicle="$vehicle"/>
            @if ($vehicle->category)<span class="chip">{{ $vehicle->category->label() }}</span>@endif
            @if ($vehicle->yard && $vehicle->state === VehicleState::Stored)<x-ui.place class="chip">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>@endif
        </x-park.vehicle-row>

        @if (($tow || $callForm) && ($contactName || $contactPhone))
            <x-ui.contact :name="$contactName ?: 'Страхователь'" icon="user">
                <x-slot:chips>
                    @if ($contactPhone)<a href="tel:+{{ preg_replace('/\D+/', '', $contactPhone) }}" class="tag nums">{{ $contactPhone }}</a>@endif
                    @if ($req->from_address)<x-ui.place class="tag">{{ $req->from_address }}</x-ui.place>@endif
                    @if ($vehicle->vendor)<span class="tag">{{ $vehicle->vendor->name }}</span>@endif
                </x-slot:chips>
                <x-slot:acts>
                    @if ($contactPhone)<a href="tel:+{{ preg_replace('/\D+/', '', $contactPhone) }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>@endif
                </x-slot:acts>
            </x-ui.contact>
        @endif

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2">
                <span class="chip {{ $req->isOverdue() ? 'bg-danger-soft text-danger' : ($open ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed') }}">{{ $open ? $req->state->label() : $req->state->label() }}</span>
                @if ($req->planned_at)<span class="chip nums {{ $req->isOverdue() ? 'text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                @if ($req->type === RequestType::Move && $req->yard)<x-ui.place class="chip">{{ $req->yard->name }}</x-ui.place>@endif
                @if ($tow && $req->yard)<x-ui.place class="chip">→ {{ $req->yard->name }}</x-ui.place>@endif
                @if ($callAgain && !$callForm)<a href="/requests/{{ $req->id }}?call=1" class="chip"><x-ui.icon name="phone" class="size-4"/> {{ $tow ? 'Эвакуатор' : ($req->delivery?->label() ?? 'Связаться') }}</a>@elseif ($req->delivery && !$tow)<span class="chip">{{ $req->delivery->label() }}</span>@endif
                @if ($req->next_call_at && $open)<span class="chip nums {{ $req->next_call_at->isPast() ? 'text-danger' : '' }}"><x-ui.icon name="phone" class="size-4"/> {{ $req->next_call_at->translatedFormat('j M, H:i') }}</span>@endif
                @if ($tow && $req->carrier)<span class="chip">{{ $req->carrier }}</span>@endif
                @if ($tow && $req->distance_km)<span class="chip nums">{{ $req->distance_km }} км</span>@endif
                @if ($tow && $req->cost)<span class="chip nums">{{ Money::rub($req->cost) }}</span>@endif
                @if ($messages->isNotEmpty())<x-mail.window-button :count="$messages->count()" chip/>@endif
                @if ($open)
                    <button type="button" class="chip person" data-action="sheet#open">@if ($req->assignee)<x-ui.avatar :user="$req->assignee" :size="20"/>{{ $req->assignee->shortName() }}@else<x-ui.icon name="user" class="size-4"/> Исполнитель@endif</button>
                    @if ($req->assignee_id !== auth()->id())<form method="post" action="/requests/{{ $req->id }}/take" class="contents">@csrf<button class="chip bg-accent-soft text-accent-text">Беру</button></form>@endif
                @elseif ($req->assignee)<x-ui.person :user="$req->assignee"/>@endif
            </div>
            @if (!$tow && !$callForm && $req->contactLine())<div class="mt-3">{{ $req->contactLine() }}</div>@endif
            @if ($req->note)<div class="mt-1 whitespace-pre-line text-ink-muted">{{ $req->note }}</div>@endif
            @if ($req->cancel_reason)<div class="mt-1 text-ink-muted">{{ $req->cancel_reason }}</div>@endif
            @if ($req->done_at)<div class="mt-3 flex flex-wrap items-center gap-1.5"><span class="tag nums">{{ $req->done_at->translatedFormat('j M, H:i') }}</span>@if ($req->doneBy)<x-ui.person :user="$req->doneBy"/>@endif</div>@endif
        </x-ui.card>
        <x-ui.sheet id="assignee" title="Исполнитель">
            <form method="post" action="/requests/{{ $req->id }}/assign" class="flex flex-col gap-2">
                @csrf
                <label class="row row-check !py-2"><span class="min-w-0 flex-1">Никто</span><span class="check"><input type="radio" name="assignee_id" value="" @checked(!$req->assignee_id)></span></label>
                @foreach ($staff as $u)
                    <label class="row row-check !py-2"><x-ui.avatar :user="$u" :size="32"/><span class="min-w-0 flex-1">{{ $u->name }}</span><span class="check"><input type="radio" name="assignee_id" value="{{ $u->id }}" @checked($req->assignee_id === $u->id)></span></label>
                @endforeach
                <x-ui.button block class="mt-2">Записать</x-ui.button>
            </form>
        </x-ui.sheet>

        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif

        @if ($open)
            {{-- ТС правится здесь же: та же форма, что на странице ТС, только без договора и повреждений; возврат сюда. --}}
            <form method="post" action="/cars/{{ $vehicle->id }}" id="vehicle-form" data-controller="vin draft">
                @csrf @method('put')
                <input type="hidden" name="back" value="/requests/{{ $req->id }}">
                <x-ui.card title="Транспортное средство">
                    <x-park.vehicle-fields :vehicle="$vehicle" :vendors="$vendors" :categories="$categories" cols="grid-cols-2 sm:grid-cols-3"/>
                    <div class="mt-3 flex justify-end"><x-ui.button variant="secondary" size="sm">Сохранить ТС</x-ui.button></div>
                </x-ui.card>
            </form>
        @endif

        @if ($callForm)
            {{-- Звонок: эвакуатор (сразу с назначением), привезёт сам (когда), не дозвонились (когда снова). --}}
            <form method="post" action="/requests/{{ $req->id }}/contact" id="act-form" class="flex flex-col gap-4" data-controller="reveal">
                @csrf
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
                        <x-ui.field name="contact_name" label="Страхователь" :value="$contactName"/>
                        <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$contactPhone"/>
                        <div class="col-span-full grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="tow">
                            <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')" span="col-span-2"/>
                            <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
                            <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
                            <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
                            <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
                            <x-ui.field name="distance_km" label="Километров" inputmode="numeric" :value="$req->distance_km"/>
                            <x-ui.field name="cost" label="Стоимость, ₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
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
            </form>
        @elseif ($tow && $open && $req->state === RequestState::New)
            {{-- Назначить: дата, откуда, куда, перевозчик, километры — стоимость из прайса, поправима. --}}
            <form method="post" action="/requests/{{ $req->id }}/schedule" id="act-form" class="flex flex-col gap-4">
                @csrf
                <x-ui.card title="Эвакуация">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')" span="col-span-2"/>
                        <x-ui.field name="from_address" label="Откуда" :value="$req->from_address" span="col-span-2"/>
                        <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
                        <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
                        <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
                        <x-ui.field name="distance_km" label="Километров" inputmode="numeric" :value="$req->distance_km"/>
                        <x-ui.field name="cost" label="Стоимость, ₽" inputmode="numeric" :value="$req->cost ?? $towCost"/>
                        <x-ui.field name="contact_name" label="Страхователь" :value="$contactName"/>
                        <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$contactPhone"/>
                    </div>
                </x-ui.card>
            </form>
        @elseif ($tow && $open && $req->state === RequestState::Scheduled)
            <form method="post" action="/requests/{{ $req->id }}/start" id="act-form" data-turbo-confirm="Эвакуатор погрузил ТС?">@csrf</form>
            <form method="post" action="/requests/{{ $req->id }}/schedule" class="flex flex-col gap-4">
                @csrf
                <x-ui.card title="Перенести">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$req->planned_at?->format('Y-m-d\TH:i')"/>
                        <x-ui.field name="carrier" label="Перевозчик" :value="$req->carrier" list="carriers-list"/>
                        <datalist id="carriers-list">@foreach ($carriers as $c)<option value="{{ $c }}">@endforeach</datalist>
                        <x-ui.field name="distance_km" label="Километров" inputmode="numeric" :value="$req->distance_km"/>
                        <x-ui.field name="cost" label="Стоимость, ₽" inputmode="numeric" :value="$req->cost"/>
                        <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—" :value="$req->yard_id"/>
                        <div class="self-end"><x-ui.button variant="secondary" block>Сохранить</x-ui.button></div>
                    </div>
                </x-ui.card>
            </form>
        @endif

        @if ($intakeForm)
            <form method="post" action="/requests/{{ $req->id }}/intake" id="act-form" class="flex flex-col gap-4">
                @csrf
                <x-ui.card title="Приём">
                    <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                        <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$req->yard_id ?? $yards->keys()->first()" required data-spots-target="yard" data-action="change->spots#sync"/>
                        <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
                        <datalist id="spots-list" data-spots-target="list"></datalist>
                        <x-ui.field name="accepted_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" span="col-span-2"/>
                        @if ($storageRate)<span class="chip nums col-span-full justify-self-start">{{ $storageRate }}</span>@endif
                    </div>
                </x-ui.card>
                @include('park.requests.inspection-fields', ['transit' => $tow])
                {{-- Два блока: кадры из письма страховой — только смотреть и сравнивать; при приёме — снимаем по слотам. --}}
                @php $mailPhotos = $vehicle->photos()->filter(fn ($m) => ($m->getCustomProperty('stage') ?? 'mail') === 'mail'); @endphp
                @if ($mailPhotos->isNotEmpty())
                    <x-ui.card :title="'Из письма'" :count="$mailPhotos->count()" data-controller="photos" data-photos-readonly-value="true">
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
            </form>
        @elseif ($open && $req->type === RequestType::Move && $vehicle->state === VehicleState::Stored)
            <form method="post" action="/requests/{{ $req->id }}/move" id="act-form">
                @csrf
                <x-ui.card title="Перестановка">
                    <div class="grid grid-cols-2 gap-3" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                        <x-ui.field name="yard_id" label="Куда" :options="$yards" :value="$req->yard_id ?? $vehicle->yard_id" required data-spots-target="yard" data-action="change->spots#sync"/>
                        <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters" :value="$vehicle->spot"/>
                        <datalist id="spots-list" data-spots-target="list"></datalist>
                    </div>
                </x-ui.card>
            </form>
        @elseif ($open && $req->type === RequestType::Release && $vehicle->state === VehicleState::Stored)
            <form method="post" action="/requests/{{ $req->id }}/release" id="act-form" class="flex flex-col gap-4" data-controller="reveal">
                @csrf
                <x-ui.card title="Выдача">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="released_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" span="col-span-2"/>
                        <div class="field col-span-full">
                            <span class="field-label">Кому</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach (ReleasedTo::cases() as $to)
                                    <label class="choice"><input type="radio" name="to" value="{{ $to->value }}" @checked(old('to', $vehicle->sold_at ? ReleasedTo::Buyer->value : null) === $to->value)><span>{{ $to->label() }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        @if ($vehicle->pickup_name || $vehicle->pickup_phone)<div class="col-span-full flex flex-wrap gap-1.5"><span class="tag">{{ $vehicle->pickup_name }}</span>@if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="tag nums">{{ $vehicle->pickup_phone }}</a>@endif @if ($vehicle->pickup_note)<span class="tag">{{ $vehicle->pickup_note }}</span>@endif</div>@endif
                        <x-ui.field name="note" label="По какому документу" type="textarea" span="col-span-full" :value="$vehicle->pickup_note"/>
                        {{-- Получатель подписывает: ТС соответствует акту приёма или нет; не соответствует и не забрал — акт с отказом, ТС остаётся.
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
                @include('park.requests.inspection-fields', ['transit' => false, 'signer' => 'Кто получил'])
                @php $intakePhotos = $vehicle->photos()->filter(fn ($m) => $m->getCustomProperty('stage') === 'intake'); @endphp
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
            </form>
        @elseif ($open && !$tow && !$callForm)
            <form method="post" action="/requests/{{ $req->id }}/close" id="act-form">
                @csrf<input type="hidden" name="done" value="1">
                <x-ui.card :title="$req->type->label()"><x-ui.field name="note" label="Что сделано" type="textarea"/></x-ui.card>
            </form>
        @endif
    </div>
    @if ($events->isNotEmpty())
        <x-ui.card title="История" class="min-w-0 lg:col-start-2 lg:row-start-1">
            <div class="flex flex-col gap-2 text-sm">
                @foreach ($events as $event)
                    <div class="flex gap-3">
                        <span class="shrink-0 text-ink-dim nums">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                        <span class="min-w-0">{{ $event->text() }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
    </div>
    @if ($messages->isNotEmpty())<x-mail.window :messages="$messages"/>@endif
    @if ($verb || $messages->isNotEmpty())
        <x-ui.action-bar>
            @if ($verb)<x-ui.button form="act-form" class="min-w-0 flex-1">{{ $verb }}</x-ui.button>@endif
            @if ($messages->isNotEmpty())<x-mail.window-button :count="$messages->count()" class="shrink-0"/>@endif
            @if ($verb)<button type="button" class="btn btn-ghost btn-round" data-controller="emit" data-action="emit#send" data-emit-event-param="cancel:open" aria-label="Отменить"><x-ui.icon name="x" class="size-5"/></button>@endif
        </x-ui.action-bar>
    @endif
    @if ($verb)
        {{-- Отмена — не одна дверь: закрыть заявку, ТС не привезут, заведена по ошибке (письмо вернётся в «Из писем»). Причина — в ленту. --}}
        @php $onlyOpen = $vehicle->requests->filter(fn ($r) => $r->isOpen())->count() <= 1; $unwind = $onlyOpen && \App\Park\Actions\UnwindVehicle::allowed($vehicle) && auth()->user()->canManagePark(); $notComing = $onlyOpen && $vehicle->state->isBefore() && auth()->user()->canManagePark(); @endphp
        <div data-controller="sheet" data-action="cancel:open@window->sheet#open" class="contents">
            <x-ui.sheet id="cancel" title="Отменить">
                <form method="post" action="/requests/{{ $req->id }}/close" id="cancel-form" class="flex flex-col gap-3">
                    @csrf<input type="hidden" name="done" value="0">
                    <x-ui.field name="note" label="Почему" type="textarea"/>
                    <x-ui.button variant="secondary" block>Закрыть заявку</x-ui.button>
                </form>
                @if ($notComing || $unwind)
                    <div class="mt-3 flex flex-col gap-2">
                        @if ($notComing)<form method="post" action="/cars/{{ $vehicle->id }}/cancel" data-turbo-confirm="ТС не привезут?">@csrf<x-ui.button variant="danger" block>Не привезут</x-ui.button></form>@endif
                        @if ($unwind)<form method="post" action="/cars/{{ $vehicle->id }}" data-turbo-confirm="Отменить заведение? ТС и заявка исчезнут, письмо вернётся в «Из писем»">@csrf @method('delete')<x-ui.button variant="ghost" block>Заведена по ошибке</x-ui.button></form>@endif
                    </div>
                @endif
            </x-ui.sheet>
        </div>
    @endif
</x-ui.shell>
