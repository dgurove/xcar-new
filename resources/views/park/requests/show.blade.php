@php
    use App\Park\{RequestType, RequestState, VehicleState, Inspection, ReleasedTo};
    use App\Support\Money;
    $open = $req->isOpen();
    $tow = $req->isTow();
    // Что делает главная кнопка — по типу заявки, её фазе и состоянию ТС.
    $verb = match (true) {
        ! $open => null,
        $tow && $req->state === RequestState::New => 'Назначить',
        $tow && $req->state === RequestState::Scheduled => 'Выехали',
        $tow && $req->state === RequestState::InProgress => 'Принять на стоянку',
        $req->type === RequestType::Intake && $vehicle->state->isBefore() => 'Принять на стоянку',
        $req->type === RequestType::Move && $vehicle->state === VehicleState::Stored => 'Переставить',
        $req->type === RequestType::Release && $vehicle->state === VehicleState::Stored => 'Выдать',
        default => $req->type->verb(),
    };
    $intakeForm = $open && (($req->type === RequestType::Intake && $vehicle->state->isBefore()) || ($tow && $req->state === RequestState::InProgress));
    $contactName = $req->contact_name ?? $vehicle->contact_name;
    $contactPhone = $req->contact_phone ?? $vehicle->contact_phone;
@endphp
<x-ui.shell :title="$req->type->label()" :back="['Сегодня', '/']" narrow>
    <div class="flex flex-col gap-4" data-controller="sheet">
        <x-park.vehicle-row :vehicle="$vehicle">
            <x-park.state :vehicle="$vehicle"/>
            @if ($vehicle->category)<span class="chip">{{ $vehicle->category->label() }}</span>@endif
        </x-park.vehicle-row>

        @if ($tow && ($contactName || $contactPhone))
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
                @if ($tow && $req->carrier)<span class="chip">{{ $req->carrier }}</span>@endif
                @if ($tow && $req->distance_km)<span class="chip nums">{{ $req->distance_km }} км</span>@endif
                @if ($tow && $req->cost)<span class="chip nums">{{ Money::rub($req->cost) }}</span>@endif
                @if ($req->thread)<a href="/mail/{{ $req->thread_id }}" class="chip"><x-ui.icon name="mail" class="size-4"/> Письмо</a>@endif
                @if ($open)
                    <button type="button" class="chip person" data-action="sheet#open">@if ($req->assignee)<x-ui.avatar :user="$req->assignee" :size="20"/>{{ $req->assignee->shortName() }}@else<x-ui.icon name="user" class="size-4"/> Исполнитель@endif</button>
                @elseif ($req->assignee)<x-ui.person :user="$req->assignee"/>@endif
            </div>
            @if (!$tow && $req->contactLine())<div class="mt-3">{{ $req->contactLine() }}</div>@endif
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

        @if ($tow && $open && $req->state === RequestState::New)
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
                        <div class="field col-span-full">
                            <span class="field-label">Категория</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach (\App\Cars\Category::cases() as $c)
                                    <label class="choice"><input type="radio" name="category" value="{{ $c->value }}" @checked(old('category', $vehicle->category?->value) === $c->value)><span>{{ $c->label() }}</span></label>
                                @endforeach
                                <label class="choice"><input type="checkbox" switch name="oversize" value="1" @checked(old('oversize', $vehicle->oversize))><span>Негабарит</span></label>
                            </div>
                        </div>
                        @if ($storageRate)<span class="chip nums col-span-full self-start">{{ $storageRate }}</span>@endif
                    </div>
                </x-ui.card>
                @include('park.requests.inspection-fields', ['transit' => $tow])
                <x-ui.card title="Фото при приёме" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
                    <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                    <div hidden data-photos-target="progress" class="mb-3">
                        <div class="mb-1 text-sm text-ink-muted" data-label></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                    </div>
                    <div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" stage="intake" :slots="$slots"/></div>
                    @if ($vehicle->photos()->isNotEmpty())<div class="mt-3">@include('park.vehicles.gallery', ['vehicle' => $vehicle])</div>@endif
                </x-ui.card>
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
            <form method="post" action="/requests/{{ $req->id }}/release" id="act-form" class="flex flex-col gap-4">
                @csrf
                <x-ui.card title="Выдача">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="released_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" span="col-span-2"/>
                        <div class="field col-span-full">
                            <span class="field-label">Кому</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach (ReleasedTo::cases() as $to)
                                    <label class="choice"><input type="radio" name="to" value="{{ $to->value }}" @checked(old('to') === $to->value)><span>{{ $to->label() }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        <x-ui.field name="signer_name" label="Кто получил" span="col-span-2"/>
                        <x-ui.field name="note" label="По какому документу" type="textarea" span="col-span-full"/>
                    </div>
                </x-ui.card>
                @include('park.requests.inspection-fields', ['transit' => false])
                <x-ui.card title="Фото при выдаче" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
                    <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                    <div hidden data-photos-target="progress" class="mb-3">
                        <div class="mb-1 text-sm text-ink-muted" data-label></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                    </div>
                    <div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" stage="release" :slots="$slots"/></div>
                </x-ui.card>
            </form>
        @elseif ($open && !$tow)
            <form method="post" action="/requests/{{ $req->id }}/close" id="act-form">
                @csrf<input type="hidden" name="done" value="1">
                <x-ui.card :title="$req->type->label()"><x-ui.field name="note" label="Что сделано" type="textarea"/></x-ui.card>
            </form>
        @endif
    </div>
    @if ($verb)
        <x-ui.action-bar>
            <x-ui.button form="act-form" class="min-w-0 flex-1">{{ $verb }}</x-ui.button>
            <form method="post" action="/requests/{{ $req->id }}/close" data-turbo-confirm="Отменить заявку?">@csrf<input type="hidden" name="done" value="0"><x-ui.button variant="ghost">Отменить</x-ui.button></form>
        </x-ui.action-bar>
    @endif
</x-ui.shell>
