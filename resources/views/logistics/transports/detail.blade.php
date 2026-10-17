{{-- Карточка вывоза (Logistics XCar, 17.10.2026): машина («!» — один ключ), что дальше словом, фразой — город и откуда.
     Владелец с телефоном (звонок нажатием) и адрес с картой — исполнителю первым делом: созвониться. Ниже — ход лесенкой
     (`Transport::steps`), документы СДЭКом (трек копируется, ссылка на отслеживание; модератору — поле трека), кадры
     приёма. Главное действие одно — подвалом во всю ширину, по шагу: админу «Выбрать исполнителя», логисту
     «Назначить», исполнителю «Созвонились» → «Забрал» → «Привёз»; рано — кнопка называет, чего ждём. Отмена — красной
     строкой внизу, админу. --}}
@php
    use App\Logistics\Choices;
    use App\Logistics\Executor;
    use App\Logistics\Point;
    use App\Logistics\TransportState as S;
    use App\Support\Money;
    use App\Support\Phone;
    use App\Support\Surface;
    $car = $t->lane();
    $admin = $me->isAdmin();
    $handles = $t->isHandledBy($me);
    $executes = $t->isExecutedBy($me) || $admin;
    $pickup = $t->isPickup();
    $open = $t->isOpen();
    $phone = Phone::normalize($t->ownerPhone());
    $from = $t->fromAddress();
    $city = $t->city();
    $mapUrl = $from ? 'https://yandex.ru/maps/?text='.rawurlencode($city && ! str_contains($from, $city) ? "{$city}, {$from}" : $from) : null;
    $id = $t->id;
    $carLink = match (true) {
        $car instanceof \App\Offers\Offer && $me->isStaff() => Surface::Crm->url('/offers/'.$car->number),
        $t->vehicle && Surface::Park->opensFor($me) => Surface::Park->url('/cars/'.$t->vehicle->id),
        default => null,
    };
    // Главное действие по шагу и кто его жмёт.
    $step = match (true) {
        ! $open => null,
        $t->state === S::New && $admin => 'executor',
        $t->state === S::Logist && ($t->isCoordinatedBy($me) || $admin) => 'logist',
        $t->state === S::Assigned && $pickup && $executes => 'call',
        in_array($t->state, [S::Assigned, S::Agreed], true) && $executes => 'pickup',
        $t->state === S::Moving && $executes => 'deliver',
        default => null,
    };
    $blocked = $step === 'pickup' ? $t->pickupBlocker($me) : null;
    $docsForm = $me->sendsLogisticsDocs() && $t->docs_by_cdek && $t->executor_id && $open && ! $t->state->picked();
    $photosEdit = $handles && $open && ! in_array($t->state, [S::Delivered, S::Cancelled], true) && $t->executor_id;
@endphp
<x-ui.detail>
    <x-ui.row-card :title="$t->title()" :photos="$car?->visiblePhotos() ?? collect()" :action="false" :blank="false">
        <x-slot:row><x-logistics.row :t="$t"/></x-slot:row>
        <x-slot:badge><x-car.keys :count="$t->car?->keys_count" label/><span class="title-state {{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span></x-slot:badge>
        <x-slot:line>
            @if ($city)<span>{{ $city }}</span>@endif
            {{-- У логиста, исполнителя ещё нет — «у логиста», а не «вывозит»; самому логисту его имя не пишем. --}}
            @if ($t->executorUser && ! $t->isExecutedBy($me))<span>вывозит {{ $t->executorName() }}</span>@elseif (! $t->executorUser && $t->coordinator && $t->coordinator_id !== $me->id)<span>у логиста {{ $t->coordinator->shortName() }}</span>@endif
            @if (! $pickup)<span>перевозка</span>@endif
        </x-slot:line>
        @if ($carLink)
            <x-slot:actions><a href="{{ $carLink }}" class="pill" data-turbo="false">{{ str_contains($carLink, '/offers/') ? 'Предложение в CRM' : 'Дело на парковке' }}</a></x-slot:actions>
        @endif
        @if ($errors->any())<x-ui.flash tone="danger" class="mt-3">{{ $errors->first() }}</x-ui.flash>@endif

        {{-- Владелец и откуда — тому, кто едет: созвониться первым делом. --}}
        @if ($pickup && ($handles || $me->sendsLogisticsDocs()))
            <div class="list mt-4">
                @if ($t->ownerName() || $phone)
                    <div class="row items-center justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">Владелец</span><span class="block break-words">{{ $t->ownerName() ?? 'Имени нет' }}</span></span>
                        @if ($phone)<a href="tel:+{{ $phone }}" class="btn btn-s btn-accent shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($phone) }}</a>@endif
                    </div>
                @endif
                @if ($from)
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="row items-start justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">Где забрать</span><span class="block whitespace-pre-line break-words">{{ $t->pickup_place ?? $from }}</span></span>
                        <x-ui.icon name="map-pin" class="size-5 shrink-0 text-accent-text"/>
                    </a>
                @endif
                @if ($t->call_note)
                    <div class="row"><span class="min-w-0"><span class="block text-sm text-ink-muted">Договорились</span><span class="block whitespace-pre-line break-words">{{ $t->call_note }}</span></span></div>
                @endif
            </div>
        @elseif (! $pickup && ($t->from_address || $t->destinationLabel()))
            <p class="mt-4 text-sm">{{ $t->from_address ?? '?' }} → {{ $t->destinationLabel() ?? '?' }}</p>
        @endif

        {{-- Ход лесенкой — тот же, что в CRM. --}}
        <div class="steps mt-5">
            @foreach ($t->steps() as $s)
                <div class="step step--{{ $s['status'] }}">
                    <span class="step-dot">@if ($s['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                    <div class="step-body">
                        <div class="step-head">
                            <span class="step-title flex-1">{{ $s['title'] }}</span>
                            @if ($s['status'] === 'done' && $s['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ $s['at']->translatedFormat('j M') }}</span>@endif
                        </div>
                        @if ($s['hint'])<p class="step-hint">{{ $s['hint'] }}</p>@endif
                        {{-- Трек: копировать и смотреть у СДЭКа; получил — отметить. --}}
                        @if ($s['key'] === 'docs' && $t->docs_track)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <x-ui.copy-code :value="$t->docs_track" class="tag" done="Трек в буфере"/>
                                <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($t->docs_track) }}" target="_blank" rel="noopener" class="text-sm text-accent-text">Где документы</a>
                            </div>
                            @if (! $t->docs_received_at && $handles && $open)
                                <form method="post" action="/transports/{{ $id }}/docs/received" class="mt-3" data-turbo-confirm="Документы у вас?">@csrf<x-ui.button size="sm">Получил документы</x-ui.button></form>
                            @endif
                        @endif
                        @if ($s['key'] === 'docs' && $docsForm)
                            <div class="mt-2 text-sm text-ink-muted">
                                {{ $t->executorUser?->name }}@if ($t->executorUser?->phone), <a href="tel:{{ $t->executorUser->phone }}" class="text-accent-text nums">{{ Phone::format(Phone::normalize($t->executorUser->phone) ?? $t->executorUser->phone) }}</a>@endif
                                @if ($t->executorUser?->cdek_point)<span class="block text-ink">СДЭК: {{ $t->executorUser->cdek_point }}</span>@endif
                            </div>
                            <form method="post" action="/transports/{{ $id }}/docs" class="mt-3 flex items-end gap-2">
                                @csrf
                                <div class="fields min-w-0 flex-1"><x-ui.field name="docs_track" id="docs-track-{{ $id }}" label="Трек-номер СДЭК" :value="$t->docs_track"/></div>
                                <x-ui.button size="sm" class="shrink-0">{{ $t->docs_track ? 'Сохранить' : 'Отправлено' }}</x-ui.button>
                            </form>
                        @endif
                        @if ($s['key'] === 'call' && $t->agreed_at && $executes && $open && ! $t->state->picked())
                            <button type="button" class="mt-2 text-sm text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="call-{{ $id }}:open">Перенести</button>
                        @endif
                        @if ($s['key'] === 'executor' && $admin && $open && ! $t->state->picked() && $t->state !== S::New)
                            <button type="button" class="mt-2 text-sm text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="executor-{{ $id }}:open">Сменить</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Админу — документы СДЭКом или без: решение у задачи (по умолчанию — только у логиста), переключается и здесь. --}}
        @if ($admin && ($t->executor_id || $t->coordinator_id) && $open && ! $t->state->picked() && $pickup)
            <form method="post" action="/transports/{{ $id }}/cdek" class="list mt-4" data-controller="autosubmit" data-action="change->autosubmit#submit">
                @csrf
                <label class="row items-center justify-between gap-4">
                    <span>Документы СДЭКом</span>
                    <input type="hidden" name="docs_by_cdek" value="0">
                    <input type="checkbox" name="docs_by_cdek" value="1" class="switch" @checked($t->docs_by_cdek)>
                </label>
            </form>
        @endif
        @if (($admin || $t->isCoordinatedBy($me)) && ($t->cost || $t->distance_km))
            <p class="mt-3 text-sm text-ink-muted">{{ implode(', ', array_filter([$t->distance_km ? $t->distance_km.' км' : null, $t->cost ? Money::rub((int) $t->cost) : null])) }}</p>
        @endif

        {{-- Пока можно жать «Забрал», фото приёма снимают в его шторке (17.10.2026, прогон: «сначала добавьте фото в карточке»
             гонял из шторки обратно); здесь — только снятые. Блок один: поток загрузки меняет его по id. --}}
        @if ($pickup && ! ($step === 'pickup' && ! $blocked) && ($photosEdit || $t->photos('pickup')->isNotEmpty()))
            <x-logistics.photos :t="$t" at="pickup" :edit="$photosEdit && ! $t->state->picked()"/>
        @endif
        @if ($t->pickup_note)<p class="mt-3 whitespace-pre-line text-sm">{{ $t->pickup_note }}</p>@endif
        @if ($t->state === S::Cancelled)<p class="mt-4 text-sm text-ink-muted">Отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif

        @if ($admin && $open && ! $t->state->picked())
            <div class="list mt-5">
                <button type="button" class="row row-danger w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="cancel-{{ $id }}:open">Отменить вывоз</button>
            </div>
        @endif

        @if ($step)
            <x-slot:foot>
                <div class="detail-action">
                    @if ($blocked)
                        <button type="button" class="btn btn-quiet w-full" disabled>{{ $blocked }}</button>
                    @elseif ($step === 'call')
                        <div class="flex gap-2">
                            <form method="post" action="/transports/{{ $id }}/missed" class="contents">@csrf<button class="btn btn-quiet shrink-0">Не дозвонился</button></form>
                            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="call-{{ $id }}:open">Созвонились</button>
                        </div>
                    @else
                        <button type="button" class="btn btn-accent w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $step }}-{{ $id }}:open">{{ match ($step) { 'executor' => 'Выбрать исполнителя', 'logist' => 'Назначить исполнителя', 'pickup' => 'Забрал', 'deliver' => 'Привёз' } }}</button>
                    @endif
                </div>
            </x-slot:foot>
        @endif
    </x-ui.row-card>

    {{-- Шторки шагов. --}}
    @if ($admin && $open && ! $t->state->picked())
        <div data-controller="sheet" data-action="executor-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'executor-'.$id" title="Кто вывозит">
                <x-logistics.assign-form :transport="$t" :action="'/transports/'.$id.'/executor'" :prefix="'executor-'.$id">@method('put')</x-logistics.assign-form>
            </x-ui.sheet>
        </div>
        <div data-controller="sheet" data-action="cancel-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'cancel-'.$id" title="Отменить вывоз">
                <form method="post" action="/transports/{{ $id }}/cancel" class="flex flex-col gap-4">
                    @csrf
                    <div class="fields"><x-ui.field name="reason" :id="'cancel-reason-'.$id" label="Почему" type="textarea"/></div>
                    <x-ui.sheet-foot variant="danger">Отменить вывоз</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($t->executor === Executor::Logist && ($t->isCoordinatedBy($me) || $admin) && $open && ! $t->state->picked() && $t->coordinator)
        <div data-controller="sheet" data-action="logist-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'logist-'.$id" title="Назначить исполнителя">
                <form method="post" action="/transports/{{ $id }}/logist" class="flex flex-col gap-4">
                    @csrf @method('put')
                    <div class="fields">
                        <x-ui.field name="executor_id" :id="'logist-who-'.$id" label="Кто вывозит" :options="Choices::contractorsOf($t->coordinator)" :value="old('executor_id', $t->executor_id)" placeholder="Выберите"/>
                        <x-ui.field name="distance_km" :id="'logist-km-'.$id" label="Расстояние, км" inputmode="numeric" :value="old('distance_km', $t->distance_km)"/>
                        <x-ui.field name="cost" :id="'logist-cost-'.$id" label="Стоимость, ₽" :value="old('cost', $t->cost ? (int) $t->cost : null)"/>
                    </div>
                    <x-ui.sheet-foot>{{ $t->executor_id ? 'Сохранить' : 'Назначить' }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($pickup && $executes && $open && in_array($t->state, [S::Assigned, S::Agreed], true))
        <div data-controller="sheet" data-action="call-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'call-'.$id" title="Созвонились">
                <form method="post" action="/transports/{{ $id }}/call" class="flex flex-col gap-4">
                    @csrf
                    <div class="fields">
                        <x-ui.field name="pickup_at" :id="'call-at-'.$id" label="Когда вывоз" type="datetime-local" default-time="12:00" :value="old('pickup_at', $t->pickup_at?->format('Y-m-d\TH:i'))" :min="now()->format('Y-m-d\TH:i')" required/>
                        <x-ui.field name="pickup_place" :id="'call-place-'.$id" label="Где забрать" type="textarea" :value="old('pickup_place', $t->pickup_place ?? $from)" required/>
                        <x-ui.field name="call_note" :id="'call-note-'.$id" label="Детали" type="textarea" :value="old('call_note', $t->call_note)"/>
                    </div>
                    <x-ui.sheet-foot>{{ $t->agreed_at ? 'Сохранить' : 'Созвонились' }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($step === 'pickup' && ! $blocked)
        <div data-controller="sheet" data-action="pickup-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'pickup-'.$id" title="Забрал" tall>
                <form method="post" action="/transports/{{ $id }}/pickup" class="flex flex-col gap-4" data-controller="reveal">
                    @csrf
                    @if ($pickup)
                        @php
                            $keysNow = old('keys');
                            $points = array_filter([
                                Point::Keeper->value => \App\Logistics\Transport::keeperLabel($t->saleOffer()),
                                Point::Ours->value => Point::Ours->label(),
                                Point::Yard->value => Point::Yard->label(),
                                Point::Other->value => Point::Other->label(),
                            ]);
                            $point = old('destination', $t->destination?->value ?? array_key_first($points));
                        @endphp
                        <x-logistics.photos :t="$t" at="pickup" edit class="!mt-0"/>
                        <div>
                            <h3 class="list-head">Сколько ключей</h3>
                            <x-ui.segment name="keys" :options="[0 => 'Нет', 1 => '1', 2 => '2', 3 => '3+']" :value="$keysNow"/>
                        </div>
                        {{-- Куда — строками с галкой, как в Настройках: сегментом из четырёх «Менеджеру Иван П.» и «Другой адрес»
                             ломались в две строки на телефоне. --}}
                        <div>
                            <h3 class="list-head">Куда везёте</h3>
                            <div class="list">
                                @foreach ($points as $value => $label)
                                    <label class="row row-check">
                                        <span class="min-w-0 flex-1">{{ $label }}</span>
                                        <span class="check"><input type="radio" name="destination" value="{{ $value }}" @checked((string) $point === (string) $value) data-action="reveal#pick"></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="fields" data-reveal-target="pane" data-reveal-key="yard" @if ($point !== 'yard') hidden @endif>
                            <x-ui.field name="to_yard_id" :id="'pickup-yard-'.$id" label="Парковка" :options="Choices::yards()" placeholder="Выберите" :value="old('to_yard_id', $t->to_yard_id)"/>
                        </div>
                        <div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
                            <x-ui.field name="to_address" :id="'pickup-address-'.$id" label="Адрес" type="textarea" :value="old('to_address', $t->to_address)"/>
                        </div>
                        <div class="fields"><x-ui.field name="pickup_note" :id="'pickup-note-'.$id" label="Комментарий" type="textarea" :value="old('pickup_note', $t->pickup_note)"/></div>
                        @error('photos')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                    <x-ui.sheet-foot>Забрал</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($step === 'deliver')
        <div data-controller="sheet" data-action="deliver-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'deliver-'.$id" title="Привёз">
                <form method="post" action="/transports/{{ $id }}/deliver" class="flex flex-col gap-4">
                    @csrf
                    <p>{{ $t->destinationLabel() ?? 'ТС на месте' }}</p>
                    <x-ui.sheet-foot>Привёз</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.detail>
