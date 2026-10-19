{{-- Карточка вывоза (Logistics XCar, 17.10.2026): машина («!» — один ключ), что дальше словом, фразой — город и откуда.
     Под названием коды: номер убытка с логотипом (сотрудникам), госномер, VIN. Ведущим логистику (админ, модераторы,
     18.10.2026) — строка «Продажа»: где продажа (`SaleState`), менеджер чипом — кому везти, этап сделки, когда поступила;
     три дня без покупателя — красным. Свежая строка списка в ответе — та же, что в списке (`list`, `pick`, `cols`).
     Владелец с телефоном (звонок нажатием) и адрес с картой — исполнителю первым делом: созвониться. Ниже — ход лесенкой
     (`Transport::steps`), документы СДЭКом (трек копируется, ссылка на отслеживание; модератору — поле трека), кадры
     приёма. Главное действие одно — подвалом во всю ширину, по шагу: админу «Выбрать исполнителя», логисту
     «Назначить», исполнителю «Созвонились» → «Забрал» → «Привёз»; рано — кнопка называет, чего ждём. Отмена — красной
     строкой внизу, админу.
     Заявка логиста от его заказчика (18.10.2026): заказчик во фразе, контакты на месте, «Деньги» — цена заказчику и
     исполнителю с отметками оплаты, «Изменить» и «Повторить»; шаги за своего исполнителя логист отмечает сам. У любой задачи
     с исполнителем — «Ссылка исполнителю»: страница без входа, текст задачи с ней — «Отправить». --}}
@php
    use App\Logistics\Choices;
    use App\Logistics\Channel;
    use App\Logistics\Point;
    use App\Logistics\TransportState as S;
    use App\Support\Money;
    use App\Support\Phone;
    $car = $t->lane();
    $admin = $me->leadsLogistics();
    $sale = $admin ? \App\Logistics\SaleState::of($t) : null;
    $offer = $t->saleOffer();
    // Этап сделки — когда забирать уже можно (иначе «после оплаты» / «ждём поставщика» говорит то же).
    $dealStage = $sale && in_array($sale->key, ['deal', 'garage'], true) && ! str_contains($sale->word, ',') ? $offer?->position()?->stage?->block?->name : null;
    $handles = $t->isHandledBy($me);
    $mine = $t->hasLogist($me);
    $order = $t->isOrder();
    // Логист отмечает шаги за своего исполнителя (у многих нет приложения — у них страница по ссылке).
    $executes = $t->isExecutedBy($me) || $admin || $mine;
    // Исполнитель (не ведущий и не логист задачи) — интерфейс проще (владелец 18.10.2026): кто владелец, где забрать, куда
    // везти, его шаги и кнопка; без истории, без строк «логист / исполнитель» и служебного.
    $solo = $t->isExecutedBy($me) && ! $admin && ! $mine;
    $pickup = $t->isPickup();
    $open = $t->isOpen();
    $phone = Phone::normalize($t->ownerPhone());
    $from = $t->fromAddress();
    $city = $t->city();
    $mapUrl = $from ? 'https://yandex.ru/maps/?text='.rawurlencode($city && ! str_contains($from, $city) ? "{$city}, {$from}" : $from) : null;
    $id = $t->id;
    // Главное действие по шагу и кто его жмёт.
    $step = match (true) {
        ! $open => null,
        $t->state === S::New && $admin => 'executor',
        $t->state === S::Logist && ($t->hasLogist($me) || $admin) => 'logist',
        $t->state === S::Assigned && $pickup && $executes => 'call',
        in_array($t->state, [S::Assigned, S::Agreed], true) && $executes => 'pickup',
        $t->state === S::Moving && $executes => 'deliver',
        default => null,
    };
    $blocked = $step === 'pickup' ? $t->pickupBlocker($me) : null;
    $docsForm = $me->sendsLogisticsDocs() && $t->docs_by_cdek && $t->executor_id && $open && ! $t->state->picked();
    $backdate = $admin && $open;
    $photosEdit = $handles && $open && ! in_array($t->state, [S::Delivered, S::Cancelled], true) && $t->executor_id;
    // Шаг исполнителя жмёт не он (логист, админ) — кнопка так и называется: «Забрал за исполнителя» (18.10.2026).
    $for = $t->executor && ! $t->isExecutedBy($me) ? ' за исполнителя' : '';
    // Сменить логиста или исполнителя — нажатием на шаг, в той же задаче, пока ТС не забрали: ведущим — «Кто вывозит»,
    // логисту — его исполнителя. Отменить — последний сделанный шаг (`StepBack`), в его строке.
    $changeable = $open && ! $t->state->picked() && ! $order;
    $change = [
        'logist' => $changeable && $admin ? 'executor' : null,
        'executor' => ! $changeable ? null : ($t->channel === Channel::Logist ? ($mine || $admin ? 'logist' : null) : ($admin && $t->state !== S::New ? 'executor' : null)),
    ];
    $undo = \App\Logistics\Actions\StepBack::last($t);
    $undo = $undo && \App\Logistics\Actions\StepBack::allowed($t, $me, $undo) ? $undo : null;
    $undoLabel = match ($undo) { 'logist' => 'Снять логиста', 'executor' => 'Снять исполнителя', null => null, default => 'Отменить «'.\App\Logistics\TransportEvent::STEPS[$undo].'»' };
@endphp
<x-ui.detail>
    {{-- Лента сверху — кадры машины, что видит этот человек (`Logistics\FileAccess`); глазами ими правят в блоке «Фото». --}}
    <x-ui.row-card :title="$t->title()" :photos="\App\Logistics\FileAccess::files($t)['photos']->filter(fn ($m) => \App\Logistics\FileAccess::visible($t, $me, $m))->values()" :action="false" :blank="false">
        <x-slot:row>@if ($list === 'documents')<x-logistics.doc-row :t="$t"/>@else<x-logistics.row :t="$t" :checkable="$pick" :cols="$cols" :preset="$preset"/>@endif</x-slot:row>
        @if (($me->isStaff() && ($t->vendorId() || $t->car?->claim_ref)) || $t->car?->plate || ($t->car?->vin && ($admin || $handles)))
            <x-slot:marks>
                @if ($me->isStaff())<x-vendor.ref :vendor-id="$t->vendorId()" :ref="$t->car?->claim_ref" copy class="tag"/>@endif
                @if ($t->car?->plate)<x-ui.copy-code :value="$t->car->plate" class="tag" done="Госномер в буфере"/>@endif
                @if ($admin || $handles)<x-ui.vin-code :vin="$t->car?->vin" class="tag"/>@endif
            </x-slot:marks>
        @endif
        <x-slot:badge><x-car.keys :count="$t->car?->keys_count" label/><x-ui.links :transport="$t"/><span class="title-state {{ $t->isLate() ? 'text-danger' : $t->state->tone() }}">{{ $t->isLate() ? 'не вывезли в срок' : $t->next() }}</span></x-slot:badge>
        <x-slot:line>
            @if ($city)<span>{{ $city }}</span>@endif
            @if (! $pickup && ! $t->from_depot_id)<span>перевозка</span>@endif
        </x-slot:line>
        @if ($order && $mine)
            <x-slot:actions>
                @if ($open)<a href="/transports/{{ $id }}/edit" class="btn btn-s btn-quiet" data-turbo-frame="_top"><x-ui.icon name="edit" class="size-4"/>Изменить</a>@endif
                <a href="/transports/new?from={{ $id }}" class="btn btn-s btn-quiet" data-turbo-frame="_top"><x-ui.icon name="refresh" class="size-4"/>Повторить</a>
            </x-slot:actions>
        @endif
        @if ($errors->any())<x-ui.flash tone="danger" class="mt-3">{{ $errors->first() }}</x-ui.flash>@endif

        {{-- Где продажа — тому, кто решает, кому отдать машину: покупатель есть — менеджер, к кому везти. --}}
        @if ($sale)
            <div class="list mt-4">
                <div class="row items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block text-sm text-ink-muted">Продажа</span>
                        <span class="block {{ $sale->tone }}">{{ $sale->word }}@if ($dealStage)<span class="text-ink-muted">, {{ mb_strtolower($dealStage) }}</span>@endif</span>
                        <span class="block text-sm text-ink-muted nums">поступила {{ \App\Support\Ago::day($t->created_at, time: true) }}</span>
                    </span>
                    @if ($sale->manager)<x-ui.person :user="$sale->manager" class="shrink-0"/>@endif
                </div>
                @if ($offer?->binding_until)
                    <div class="row items-center justify-between gap-3">
                        <span class="text-sm text-ink-muted">Обязывающее до</span>
                        <x-offer.binding :offer="$offer" plain class="text-right"/>
                    </div>
                @endif
            </div>
        @endif

        {{-- Заказчик заявки логиста — строкой с телефоном (18.10.2026: во фразе «вывозит Пётр Э., ООО …» его не было видно). --}}
        @if ($order && $mine && $t->client)
            <div class="list mt-4">
                <div class="row items-center justify-between gap-4">
                    <span class="min-w-0"><span class="block text-sm text-ink-muted">Заказчик</span><span class="block break-words">{{ $t->client->name }}</span>@if ($t->client->contact_name)<span class="block text-sm text-ink-muted">{{ $t->client->contact_name }}</span>@endif</span>
                    @if ($t->client->phone)<a href="tel:+{{ $t->client->phone }}" class="btn btn-s btn-quiet shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($t->client->phone) }}</a>@endif
                </div>
            </div>
        @endif

        {{-- Владелец и откуда — тому, кто едет: созвониться первым делом. Отрезок со стоянки логиста — откуда: стоянка, кто
             на месте и телефон. --}}
        @php $fromDepot = $t->fromDepot; @endphp
        @if (($pickup || $fromDepot) && ($handles || $me->sendsLogisticsDocs()))
            <div class="list mt-4">
                @if ($fromDepot && ($fromDepot->contact_name || $fromDepot->phone))
                    <div class="row items-center justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">На стоянке</span><span class="block break-words">{{ $fromDepot->contact_name ?? 'Телефон' }}</span></span>
                        @if ($fromDepot->phone)<a href="tel:+{{ $fromDepot->phone }}" class="btn btn-s btn-accent shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($fromDepot->phone) }}</a>@endif
                    </div>
                @endif
                @if ($pickup && ($t->ownerName() || $phone || $t->ownerPhone2()))
                    <div class="row items-center justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">{{ $order ? 'У кого забрать' : 'Владелец' }}</span><span class="block break-words">{{ $t->ownerName() ?? 'Имени нет' }}</span></span>
                        <span class="flex shrink-0 flex-col items-end gap-1.5">@if ($phone)<a href="tel:+{{ $phone }}" class="btn btn-s btn-accent shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($phone) }}</a>@endif
                    @if ($t->ownerPhone2())<a href="tel:+{{ $t->ownerPhone2() }}" class="btn btn-s btn-quiet shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($t->ownerPhone2()) }}</a>@endif</span>
                    </div>
                @endif
                @if ($from)
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="row items-start justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">Где забрать</span><span class="block whitespace-pre-line break-words">{{ $t->pickup_place ?? $from }}</span></span>
                        <x-ui.icon name="map-pin" class="size-5 shrink-0 text-accent-text"/>
                    </a>
                @endif
                {{-- Кто и куда — строками (`x-logistics.facts`): нажатием — окно, где это меняют. Заявка логиста — свой адрес
                     с тем, кто принимает. --}}
                @if ($order && $t->to_address)
                    <div class="row items-center justify-between gap-4">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">Куда везти</span><span class="block break-words">{{ $t->to_address }}</span>@if ($t->to_contact)<span class="block text-sm text-ink-muted">принимает {{ $t->to_contact }}</span>@endif</span>
                        @if ($t->to_phone)<a href="tel:+{{ $t->to_phone }}" class="btn btn-s btn-quiet shrink-0 nums"><x-ui.icon name="phone" class="size-4"/>{{ Phone::format($t->to_phone) }}</a>@endif
                    </div>
                @endif
                <x-logistics.facts :t="$t" :me="$me" :brief="$solo" :logist="$change['logist'] ? $change['logist'].'-'.$id.':open' : null"
                    :who="$change['executor'] ? $change['executor'].'-'.$id.':open' : null" :where="! $order && $open && $handles ? 'route-'.$id.':open' : null"/>
                @if ($t->call_note)
                    <div class="row"><span class="min-w-0"><span class="block text-sm text-ink-muted">Договорились</span><span class="block whitespace-pre-line break-words">{{ $t->call_note }}</span></span></div>
                @endif
            </div>
        @elseif (! $pickup && ! $fromDepot && ($t->from_address || $t->destinationLabel()))
            <p class="mt-4 text-sm">{{ $t->from_address ?? '?' }} → {{ $t->destinationLabel() ?? '?' }}</p>
        @endif

        {{-- Отрезки через стоянку логиста — ведущим и логисту (исполнителю — только свой). --}}
        @unless ($solo)
            <x-logistics.legs :t="$t" :href="fn ($leg) => '/transports?preset=all&peek='.$leg->id" class="mt-4"/>
        @endunless

        {{-- Фото и документы машины с глазами «кто видит» (19.10.2026). --}}
        <x-logistics.files :t="$t"/>

        {{-- Ход лесенкой — тот же, что в CRM. --}}
        <div class="steps mt-5">
            @foreach ($t->steps() as $s)
                @continue(in_array($s['key'], ['logist', 'executor'], true) && $t->state !== S::New && $t->channel !== Channel::Manager)
                <div class="step step--{{ $s['status'] }}">
                    <span class="step-dot">@if ($s['status'] === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
                    <div class="step-body">
                        <div class="step-head">
                            <span class="step-title flex-1">{{ $s['title'] }}</span>
                            @if ($s['status'] === 'done' && $s['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::stamp($s['at'], time: true) }}</span>@endif
                        </div>
                        @if ($s['hint'])<p class="step-hint">{{ $s['hint'] }}</p>@endif
                        {{-- Трек: копировать и смотреть у СДЭКа; получил — отметить. --}}
                        @if ($s['key'] === 'docs' && $t->docs_track)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <x-ui.copy-code :value="$t->docs_track" class="tag" done="Трек в буфере"/>
                                <a href="https://www.cdek.ru/ru/tracking?order_id={{ urlencode($t->docs_track) }}" target="_blank" rel="noopener" class="text-sm text-accent-text">Где документы</a>
                            </div>
                            @if (! $t->docs_received_at && $handles && $open)
                                <form method="post" action="/transports/{{ $id }}/docs/received" class="mt-3" data-turbo-confirm="{{ $for ? 'Документы у исполнителя?' : 'Документы у вас?' }}">@csrf<x-ui.button size="sm">Получил документы{{ $for }}</x-ui.button></form>
                            @endif
                        @endif
                        @if ($s['key'] === 'docs' && $docsForm)
                            <div class="mt-2 text-sm text-ink-muted">
                                {{ $t->executor?->name }}@if ($t->executor?->phone), <a href="tel:{{ $t->executor->phone }}" class="text-accent-text nums">{{ Phone::format(Phone::normalize($t->executor->phone) ?? $t->executor->phone) }}</a>@endif
                                @if ($t->executor?->cdek_point)<span class="block text-ink">СДЭК: {{ $t->executor->cdek_point }}</span>@endif
                            </div>
                            <form method="post" action="/transports/{{ $id }}/docs" class="mt-3 flex flex-col gap-2">
                                @csrf
                                <div class="fields"><x-ui.field name="docs_track" id="docs-track-{{ $id }}" label="Трек СДЭК" :value="$t->docs_track"/></div>
                                <x-ui.button size="sm" class="self-start">{{ $t->docs_track ? 'Сохранить трек' : 'Документы отправлены' }}</x-ui.button>
                            </form>
                        @endif
                        @if ($s['key'] === 'call' && $t->agreed_at && $executes && $open && ! $t->state->picked())
                            <button type="button" class="mt-2 text-sm text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="call-{{ $id }}:open">Перенести</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- История: кто что нажал, за кого и когда (`TransportEvent`). --}}
        @if (! $solo && $t->events()->exists())
            <h3 class="list-head mt-5">История</h3>
            <div class="list"><div class="row"><x-logistics.history :t="$t" :me="$me" class="w-full"/></div></div>
        @endif

        {{-- Админу — документы СДЭКом или без: решение у задачи (по умолчанию — только у логиста), переключается и здесь. --}}
        @if ($admin && ($t->executor_id || $t->logist_id) && $open && ! $t->state->picked() && $pickup)
            <form method="post" action="/transports/{{ $id }}/cdek" class="list mt-4" data-controller="autosubmit" data-action="change->autosubmit#submit">
                @csrf
                <label class="row items-center justify-between gap-4">
                    <span>Документы СДЭКом</span>
                    <input type="hidden" name="docs_by_cdek" value="0">
                    <input type="checkbox" name="docs_by_cdek" value="1" class="switch" @checked($t->docs_by_cdek)>
                </label>
            </form>
        @endif
        @if (! $order && ($admin || $mine) && ($t->cost || $t->distance_km))
            <p class="mt-3 text-sm text-ink-muted">{{ implode(', ', array_filter([$t->distance_km ? $t->distance_km.' км' : null, $t->cost ? Money::rub((int) $t->cost) : null])) }}</p>
        @endif

        {{-- Деньги логиста — отметкой (18.10.2026): заказчик заплатил ему, он — исполнителю. Повторное нажатие снимает. --}}
        @php
            $money = $mine ? array_filter([
                'client' => $order && $t->client_price ? ['Заказчик', $t->client_price, $t->client_paid_at, 'получено', 'Деньги пришли'] : null,
                'executor' => $t->executor_cost && $t->executor_id && $t->executor_id !== $me->id ? ['Исполнитель', $t->executor_cost, $t->executor_paid_at, 'выплачено', 'Выплачено'] : null,
            ]) : [];
        @endphp
        @if ($money)
            <h3 class="list-head mt-5">Деньги</h3>
            <div class="list">
                @foreach ($money as $side => [$who, $sum, $paidAt, $done, $verb])
                    <form method="post" action="/transports/{{ $id }}/paid" class="row items-center justify-between gap-3"
                        @if ($paidAt) data-turbo-confirm="Снять отметку «{{ $done }}»?" @endif>
                        @csrf
                        <input type="hidden" name="side" value="{{ $side }}"><input type="hidden" name="paid" value="{{ $paidAt ? 0 : 1 }}">
                        <span class="min-w-0"><span class="block text-sm text-ink-muted">{{ $who }}</span><span class="block nums">{{ Money::rub((int) $sum) }}</span></span>
                        @if ($paidAt)
                            <button class="shrink-0 text-sm text-accent-text">{{ $done }} {{ \App\Support\Ago::day($paidAt) }}</button>
                        @elseif ($side === 'executor' || $t->state === S::Delivered)
                            <button class="btn btn-s btn-quiet shrink-0">{{ $verb }}</button>
                        @else
                            <span class="shrink-0 text-sm text-ink-muted">после «Привёз»</span>
                        @endif
                    </form>
                @endforeach
            </div>
        @endif

        {{-- Ссылка исполнителю (18.10.2026): страница задачи без входа — «Созвонились», «Забрал» с фото, «Привёз». --}}
        @if ($open && $t->tripUrl() && ($admin || $mine) && ! $t->isExecutedBy($me) && $t->channel !== Channel::Manager)
            <h3 class="list-head mt-5">Исполнителю {{ $t->executor?->shortName() }}</h3>
            <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $t->tripUrl() }}" data-copy-title-value="Вывоз {{ $t->title() }}" data-copy-done-value="Задача и ссылка в буфере">
                <textarea hidden data-copy-target="message">{{ \App\Logistics\TripText::for($t) }}</textarea>
                <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
                <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
                <a href="{{ $t->tripUrl() }}" target="_blank" rel="noopener" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="link"/></span>Открыть</a>
            </div>
        @endif

        {{-- Пока можно жать «Забрал», фото приёма снимают в его шторке (17.10.2026, прогон: «сначала добавьте фото в карточке»
             гонял из шторки обратно); здесь — снятые, для просмотра (`view`, свой id — поток загрузки меняет оба). --}}
        @if ($pickup && $step === 'pickup' && ! $blocked)
            <x-logistics.photos :t="$t" at="pickup" view/>
        @elseif ($pickup && ($photosEdit || $t->photos('pickup')->isNotEmpty()))
            <x-logistics.photos :t="$t" at="pickup" :edit="$photosEdit && ! $t->state->picked()"/>
        @endif
        @if ($t->pickup_note)<p class="mt-3 whitespace-pre-line text-sm">{{ $t->pickup_note }}</p>@endif
        @if ($t->state === S::Cancelled)<p class="mt-4 text-sm text-ink-muted">Отменён{{ $t->cancel_reason ? ': '.$t->cancel_reason : '' }}</p>@endif

        {{-- Назад — группой в конце, как в Настройках: отменить последний шаг (`StepBack`), ниже красным — отменить вывоз. --}}
        @php $cancels = ($admin && ! $order) || ($order && $mine) ? $open && ! $t->state->picked() : false; @endphp
        @if ($undo || $cancels)
            <div class="list mt-5">
                @if ($undo)
                    <form method="post" action="/transports/{{ $id }}/back" class="contents" data-step-back="transport" data-turbo-confirm="{{ $undoLabel }}?" data-turbo-confirm-label="{{ str_starts_with($undoLabel, 'Снять') ? 'Снять' : 'Отменить' }}">
                        @csrf<button class="row w-full text-left">{{ $undoLabel }}</button>
                    </form>
                @endif
                @if ($cancels)
                    <button type="button" class="row row-danger w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="cancel-{{ $id }}:open">{{ $order ? 'Отменить заявку' : 'Отменить вывоз' }}</button>
                @endif
            </div>
        @endif

        {{-- «Уже вывезли» — ведущим у открытой задачи: тихой строкой под главным действием (вывоз задним числом). --}}
        @if ($step || $backdate)
            <x-slot:foot>
                <div class="detail-action">
                    @if ($step)
                    @if ($blocked)
                        <button type="button" class="btn btn-quiet w-full" disabled>{{ $blocked }}</button>
                    @elseif ($step === 'call')
                        <div class="flex gap-2">
                            <form method="post" action="/transports/{{ $id }}/missed" class="contents">@csrf<button class="btn btn-quiet shrink-0">Не дозвонился</button></form>
                            <button type="button" class="btn btn-accent min-w-0 flex-1 !whitespace-normal !px-4 text-center !leading-tight" data-controller="emit" data-action="emit#send" data-emit-event-param="call-{{ $id }}:open">Созвонились{{ $for }}</button>
                        </div>
                    @else
                        <button type="button" class="btn btn-accent w-full" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $step }}-{{ $id }}:open">{{ match ($step) { 'executor' => 'Выбрать исполнителя', 'logist' => 'Назначить исполнителя', 'pickup' => 'Забрал'.$for, 'deliver' => 'Привёз'.$for } }}</button>
                    @endif
                    @endif
                    @if ($backdate)<button type="button" @class(['retreat-link', '!mt-0' => ! $step]) data-controller="emit" data-action="emit#send" data-emit-event-param="backdate-{{ $id }}:open">Уже вывезли</button>@endif
                </div>
            </x-slot:foot>
        @endif
    </x-ui.row-card>

    {{-- Шторки шагов. --}}
    @if ($backdate)
        <div data-controller="sheet" data-action="backdate-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'backdate-'.$id" title="Уже вывезли" tall>
                <x-logistics.backdate-form :transport="$t" :action="'/transports/'.$id.'/backdate'" :prefix="'backdate-'.$id"/>
            </x-ui.sheet>
        </div>
    @endif
    @if ($admin && ! $order && $open && ! $t->state->picked())
        <div data-controller="sheet" data-action="executor-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'executor-'.$id" :title="$t->channel ? 'Вывоз' : 'Назначить вывоз'" tall>
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
    @if ($order && $mine && $open && ! $t->state->picked())
        <div data-controller="sheet" data-action="cancel-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'cancel-'.$id" title="Отменить заявку">
                <form method="post" action="/transports/{{ $id }}/cancel" class="flex flex-col gap-4">
                    @csrf
                    <div class="fields"><x-ui.field name="reason" :id="'cancel-reason-'.$id" label="Почему" type="textarea"/></div>
                    <x-ui.sheet-foot variant="danger">Отменить заявку</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if (! $order && $t->channel === Channel::Logist && ($mine || $admin) && $open && ! $t->state->picked() && $t->logist)
        <div data-controller="sheet" data-action="logist-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'logist-'.$id" :title="$t->executor_id ? 'Исполнитель' : 'Назначить исполнителя'">
                <form method="post" action="/transports/{{ $id }}/logist" class="flex flex-col gap-4">
                    @csrf @method('put')
                    {{-- Куда везёт исполнитель (18.10.2026): туда, куда указано, или сначала на свою стоянку — тогда указанное
                         достанется следующему отрезку. Стоянку выбирают и заводят тут же. --}}
                    @php
                        $target = $t->destination === Point::Depot ? $t->nextLabel() : $t->routeLabel();
                        $leg = old('leg', $t->destination === Point::Depot ? 'depot' : 'route');
                    @endphp
                    <div class="flex flex-col gap-3" data-controller="reveal">
                        <h3 class="list-head -mb-1">Куда везёт исполнитель</h3>
                        <div class="list">
                            <label class="row row-check">
                                <span class="min-w-0 flex-1"><span class="block break-words {{ $target ? '' : 'text-urgent' }}">{{ $target ?? 'Пока неизвестно' }}</span>@if ($t->reach)<span class="block text-sm text-ink-muted">{{ $t->reach->label() }}</span>@endif</span>
                                <span class="check"><input type="radio" name="leg" value="route" @checked($leg === 'route') data-action="reveal#pick"></span>
                            </label>
                            <label class="row row-check">
                                <span class="min-w-0 flex-1">На стоянку</span>
                                <span class="check"><input type="radio" name="leg" value="depot" @checked($leg === 'depot') data-action="reveal#pick"></span>
                            </label>
                        </div>
                        <div class="fields" data-reveal-target="pane" data-reveal-key="depot" @if ($leg !== 'depot') hidden @endif>
                            <x-people.pick name="depot_id" label="Стоянка" kind="depot" :people="\App\Support\People::depotsOf($t->logist)" :value="old('depot_id', $t->depot_id)" title="Стоянка" :owner="$t->logist_id"/>
                        </div>
                        @if ($t->comment)<p class="whitespace-pre-line px-1 text-sm text-ink-muted">{{ $t->comment }}</p>@endif
                    </div>
                    <div class="fields">
                        {{-- Свои строками с городом и «в работе N» — видно, кто свободен; новый — тут же (18.10.2026). --}}
                        <x-people.pick name="executor_id" label="Исполнитель" kind="contractor" :people="\App\Support\People::contractorsOf($t->logist)" :value="old('executor_id', $t->executor_id)" title="Исполнитель" link required/>
                        <x-ui.field name="distance_km" :id="'logist-km-'.$id" label="Расстояние, км" inputmode="numeric" :value="old('distance_km', $t->distance_km)"/>
                        <x-ui.field name="cost" :id="'logist-cost-'.$id" label="Стоимость, ₽" :value="old('cost', $t->cost ? (int) $t->cost : null)"/>
                        {{-- Своему исполнителю — для её «Оплат» (18.10.2026), XCar этого не видит. --}}
                        @if ($mine)<x-ui.field name="executor_cost" :id="'logist-ecost-'.$id" label="Исполнителю, ₽" :value="old('executor_cost', $t->executor_cost ? (int) $t->executor_cost : null)"/>@endif
                    </div>
                    <x-ui.sheet-foot>{{ $t->executor_id ? 'Сохранить' : 'Назначить' }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if (($pickup || $t->from_depot_id) && ! $order && $open && $handles)
        @php $routeFull = \App\Logistics\Actions\SetRoute::full($t, $me); @endphp
        <div data-controller="sheet" data-action="route-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'route-'.$id" title="Куда везти">
                <form method="post" action="/transports/{{ $id }}/route" class="flex flex-col gap-4">
                    @csrf @method('put')
                    @if ($routeFull)<x-ui.segment name="reach" :options="\App\Logistics\Reach::options()" :value="old('reach', $t->reach?->value)"/>@endif
                    @error('reach')<p class="field-error -mt-2">{{ $message }}</p>@enderror
                    <x-logistics.route-fields :t="$t" :prefix="'route-'.$id" unknown/>
                    @if ($routeFull)<div class="fields"><x-ui.field name="comment" :id="'route-comment-'.$id" label="Комментарий" type="textarea" :value="old('comment', $t->comment)" placeholder="Например: пока неизвестно, кому везти"/></div>@endif
                    <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($pickup && $executes && $open && in_array($t->state, [S::Assigned, S::Agreed], true))
        <div data-controller="sheet" data-action="call-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'call-'.$id" :title="'Созвонились'.$for">
                <form method="post" action="/transports/{{ $id }}/call" class="flex flex-col gap-4">
                    @csrf
                    <div class="fields">
                        <x-ui.field name="pickup_at" :id="'call-at-'.$id" label="Когда вывоз" type="datetime-local" default-time="12:00" :value="old('pickup_at', $t->pickup_at?->format('Y-m-d\TH:i'))" :min="now()->format('Y-m-d\TH:i')" required/>
                        <x-ui.field name="pickup_place" :id="'call-place-'.$id" label="Где забрать" type="textarea" :value="old('pickup_place', $t->pickup_place ?? $from)" required/>
                        <x-ui.field name="call_note" :id="'call-note-'.$id" label="Детали" type="textarea" :value="old('call_note', $t->call_note)"/>
                    </div>
                    <x-ui.sheet-foot>{{ $t->agreed_at ? 'Сохранить' : 'Созвонились'.$for }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($step === 'pickup' && ! $blocked)
        <div data-controller="sheet" data-action="pickup-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'pickup-'.$id" :title="'Забрал'.$for" tall>
                <form method="post" action="/transports/{{ $id }}/pickup" class="flex flex-col gap-4" data-controller="reveal">
                    @csrf
                    @if ($pickup)<x-logistics.pickup-fields :t="$t"/>@endif
                    <x-ui.sheet-foot>Забрал{{ $for }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
    @if ($step === 'deliver')
        <div data-controller="sheet" data-action="deliver-{{ $id }}:open@window->sheet#open" class="contents">
            <x-ui.sheet :id="'deliver-'.$id" :title="'Привёз'.$for">
                <form method="post" action="/transports/{{ $id }}/deliver" class="flex flex-col gap-4">
                    @csrf
                    <p>{{ $t->destinationLabel() ?? 'ТС на месте' }}</p>
                    <x-ui.sheet-foot>Привёз{{ $for }}</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</x-ui.detail>
