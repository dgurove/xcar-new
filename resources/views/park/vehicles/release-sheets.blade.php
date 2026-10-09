{{-- Шторки выдачи — общие у дела (`show`) и окна заявки (`detail`): «Покупатель» (кто заберёт, sold:open), «Покупатель
     отказался» (refusal:open) и «Выдать без QR» (without-qr:open). Поле причины без QR привязано к форме выдачи — `$formId`
     (в деле `act-form`, в окне — форма выдачи карточки). Нужны `$vehicle`, `$req`, `$canManage`, `$byQr`. --}}
@php use App\Park\{RequestType, VehicleState}; $state = $vehicle->state; @endphp
    @if ($canManage && ($state === VehicleState::Stored || $vehicle->sold_at))
        <div data-controller="sheet" data-action="sold:open@window->sheet#open" class="contents">
            <x-ui.sheet id="sold" title="Покупатель" :open="$errors->hasAny(['sold_at', 'pickup_name', 'pickup_phone'])">
                {{-- Страховая продала ТС: дата письма, кому выдать; дальше дни за счёт вендора и покупатель по множителю. --}}
                <form method="post" action="/cars/{{ $vehicle->id }}/sold" class="flex flex-col gap-3">
                    @csrf
                    <div class="fields">
                        <x-ui.field name="sold_at" label="Дата реализации" type="date" :value="($vehicle->sold_at ?? now())->toDateString()" required/>
                        <x-ui.field name="pickup_phone" label="Телефон" type="tel" :value="$vehicle->pickup_phone"/>
                        <x-ui.field name="pickup_name" label="Кто заберёт" :value="$vehicle->pickup_name"/>
                        {{-- Срок из письма страховой: опоздал — дальше сутки идут покупателю по множителю вендора.
                             Поле только у вендоров с этим правилом (ВСК): у остальных платит страховая, сколько бы ни стояло. --}}
                        @if ($vehicle->vendor?->buyer_pays_late)<x-ui.field name="buyer_free_until" label="За счёт вендора до" type="date" :value="$vehicle->buyer_free_until?->toDateString()"/>@endif
                    </div>
                    <div class="sheet-foot flex gap-2">
                        <x-ui.button class="flex-1">{{ $vehicle->sold_at ? 'Сохранить' : 'Записать' }}</x-ui.button>
                        @if ($vehicle->sold_at)<x-ui.button variant="ghost" name="clear" value="1" data-turbo-confirm="Снять выдачу?">Снять выдачу</x-ui.button>@endif
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
                    <div class="sheet-foot"><x-ui.button block>Записать отказ</x-ui.button></div>
                </form>
            </x-ui.sheet>
        </div>
    @endif

    @if ($canManage && ($byQr ?? false) && $state === VehicleState::Stored && $req?->type === RequestType::Release)
        <div data-controller="sheet" data-action="without-qr:open@window->sheet#open" class="contents">
            <x-ui.sheet id="without-qr" title="Выдать без QR-кода?" :open="$errors->has('without_qr_reason')">
                {{-- Крайний случай: причина от 20 знаков уходит в историю ТС, в акт и владельцу в Telegram. Поле связано с
                     формой выдачи (form="{{ $formId }}"): дата и остальное берутся оттуда. Счётчик — внутри поля,
                     кнопка оживает на 20 знаках. --}}
                <div class="flex flex-col gap-3" data-controller="min-length" data-min-length-min-value="20">
                    <div class="list">
                        <div class="row justify-between"><span class="min-w-0"><span class="block truncate">{{ $vehicle->titleWithYear() }}</span>@if ($vehicle->pickup_name)<span class="block truncate text-sm text-ink-muted">{{ $vehicle->pickup_name }}</span>@endif</span></div>
                    </div>
                    <div class="field {{ $errors->has('without_qr_reason') ? 'field-invalid' : '' }}">
                        <label for="without-qr-reason" class="field-label">Почему выдаёте без QR</label>
                        <div class="relative">
                            <textarea id="without-qr-reason" name="without_qr_reason" form="{{ $formId }}" rows="4" class="field-input pb-8" data-min-length-target="input" data-action="input->min-length#check">{{ old('without_qr_reason') }}</textarea>
                            <span class="nums pointer-events-none absolute bottom-2.5 right-3.5 text-xs text-ink-dim" data-min-length-target="counter"></span>
                        </div>
                        @error('without_qr_reason')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sheet-foot"><button type="submit" form="{{ $formId }}" name="without_qr" value="1" class="btn btn-danger btn-block disabled:opacity-50" data-min-length-target="submit" data-action="min-length#guard" data-turbo-confirm="Выдать ТС без QR-кода? Об этом узнает владелец">Выдать без QR</button></div>
                </div>
            </x-ui.sheet>
        </div>
    @endif
