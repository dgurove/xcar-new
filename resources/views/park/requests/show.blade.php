@php
    use App\Park\{RequestType, RequestState, VehicleState};
    // Что делает главная кнопка — по типу заявки и состоянию машины.
    $verb = match (true) {
        ! $req->isOpen() => null,
        $req->type === RequestType::Intake && $vehicle->state === VehicleState::Expected => 'Принять на стоянку',
        $req->type === RequestType::Move && $vehicle->state === VehicleState::Stored => 'Переставить',
        $req->type === RequestType::Release && $vehicle->state === VehicleState::Stored => 'Выдать',
        default => $req->type->verb(),
    };
@endphp
<x-ui.shell :title="$req->type->label().' · '.$vehicle->titleWithYear()" narrow>
    <div class="flex flex-col gap-4">
        <x-park.vehicle-row :vehicle="$vehicle">
            <x-park.state :vehicle="$vehicle"/>
        </x-park.vehicle-row>

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2">
                <span class="chip {{ $req->isOverdue() ? 'bg-danger-soft text-danger' : ($req->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed') }}">{{ $req->isOpen() ? 'Ждёт' : $req->state->label() }}</span>
                @if ($req->planned_at)<span class="chip">{{ $req->planned_at->translatedFormat('j M, H:i') }}</span>@endif
                @if ($req->type === RequestType::Move && $req->yard)<span class="chip">→ {{ $req->yard->name }}</span>@endif
                @if ($req->thread)<a href="/pochta/{{ $req->thread_id }}" class="chip"><x-ui.icon name="mail" class="size-4"/> Письмо</a>@endif
            </div>
            @if ($req->contact)<div class="mt-3">{{ $req->contact }}</div>@endif
            @if ($req->note)<div class="mt-1 whitespace-pre-line text-ink-muted">{{ $req->note }}</div>@endif
            @if ($req->done_at)<div class="mt-2 text-sm text-ink-muted">{{ $req->done_at->translatedFormat('j M, H:i') }}{{ $req->assignee ? ' · '.$req->assignee->name : '' }}</div>@endif
        </x-ui.card>

        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif

        @if ($req->isOpen() && $req->type === RequestType::Intake && $vehicle->state === VehicleState::Expected)
            <form method="post" action="/zayavki/{{ $req->id }}/priem" id="act-form" class="flex flex-col gap-4">
                @csrf
                <x-ui.card title="Приём">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$req->yard_id" required/>
                        <x-ui.field name="accepted_at" label="Когда принята" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
                        <div class="field col-span-full">
                            <span class="field-label">Повреждения</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($zones as $zone)
                                    <label class="choice"><input type="checkbox" name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', [])))><span>{{ $zone->label() }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        <x-ui.field name="damage_note" label="Что ещё заметили" type="textarea" span="col-span-full"/>
                    </div>
                </x-ui.card>
                <x-ui.card title="Фото при приёме" data-controller="photos" data-photos-url-value="/mashiny/{{ $vehicle->id }}/media">
                    <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
                    <div hidden data-photos-target="progress" class="mb-3">
                        <div class="mb-1 text-sm text-ink-muted" data-label></div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
                    </div>
                    @include('park.vehicles.gallery', ['vehicle' => $vehicle])
                </x-ui.card>
            </form>
        @elseif ($req->isOpen() && $req->type === RequestType::Move && $vehicle->state === VehicleState::Stored)
            <form method="post" action="/zayavki/{{ $req->id }}/perestanovka" id="act-form">
                @csrf
                <x-ui.card title="Перестановка"><x-ui.field name="yard_id" label="Куда" :options="$yards" :value="$req->yard_id" required/></x-ui.card>
            </form>
        @elseif ($req->isOpen() && $req->type === RequestType::Release && $vehicle->state === VehicleState::Stored)
            <form method="post" action="/zayavki/{{ $req->id }}/vydacha" id="act-form">
                @csrf
                <x-ui.card title="Выдача">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="released_at" label="Когда выдана" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
                        <x-ui.field name="note" label="Кому, по какому документу" type="textarea" span="col-span-full"/>
                    </div>
                </x-ui.card>
            </form>
        @elseif ($req->isOpen())
            <form method="post" action="/zayavki/{{ $req->id }}/zakryt" id="act-form">
                @csrf<input type="hidden" name="done" value="1">
                <x-ui.card :title="$req->type->label()"><x-ui.field name="note" label="Что сделано" type="textarea"/></x-ui.card>
            </form>
        @endif
    </div>
    @if ($verb)
        <x-ui.action-bar>
            <x-ui.button form="act-form" class="min-w-0 flex-1">{{ $verb }}</x-ui.button>
            <form method="post" action="/zayavki/{{ $req->id }}/zakryt" data-turbo-confirm="Отменить заявку?">@csrf<input type="hidden" name="done" value="0"><x-ui.button variant="ghost">Отменить</x-ui.button></form>
        </x-ui.action-bar>
    @endif
</x-ui.shell>
