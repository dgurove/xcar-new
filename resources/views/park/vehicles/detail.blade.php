{{-- Карточка строки таблицы машин стоянки (фрейм detail) — работа с ТС без страницы:
     лента фото, метки, дней на стоянке; у заявки — письма и телефон (`task`), приём и выдача — окном действия; переставить
     (метка парковки — кнопка шторки «Переставить»), выдать (дата, с
     подтверждением), акты; спорные поля машины строками группы со страхователем и продажей (`x-car.variants`: выбрать, что верно), заявки строками и
     заметка с последними событиями. Формы отвечают в карточку (DetailBack), строка — свежей из row. --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $href = '/cars/'.$vehicle->id;
    // Выданная — итог дела: кому и как выдали (событие выдачи); срок и сумма — полоской (`x-park.stay`).
    $released = $state === VehicleState::Released ? $vehicle->events->firstWhere('type', \App\Park\EventType::Released) : null;
    $handedTo = $released ? ($vehicle->pickup_name ?: ($released->payload['to'] ?? null)) : null;
@endphp
<x-ui.detail>
    {{-- Запасной «Открыть» на месте пустого ряда действий не нужен: дело открывает значок в полосе карточки. --}}
    <x-ui.row-card :href="$href" :title="$vehicle->titleWithYear()" :photos="$vehicle->visiblePhotos()" :blank="false" :action="false">
        {{-- «Поделиться» — в полосе окошка, как у предложения в CRM; админам и администраторам парковки (02.11.2026). --}}
        @if (auth()->user()->sharesPark() && $vehicle->photos()->isNotEmpty())
            <x-slot:tools><x-ui.share :subject="\App\Offers\Share\Subject::vehicle($vehicle)" icon class="bar-btn"/></x-slot:tools>
        @endif
        {{-- Продажа — кнопкой на виду (11.10.2026, владелец: «куда-то делась кнопка, не можем найти» — пункт в значке связи
             не находили): строкой группы над задачей, как «Заполнить из документов»; и в «Заявках». --}}
        {{-- Состояние — посередине полосы окошка (09.11.2026, владелец: у названия «то там, то там скачет»), у стоящей его нет:
             полоска срока и так говорит «на парковке». У названия — госномер сразу за ним и значок связи (12.10.2026). --}}
        @if ($state !== VehicleState::Stored)
            <x-slot:status><x-ui.state :tone="$state->tone()">{{ mb_strtolower($state->label()) }}</x-ui.state></x-slot:status>
        @endif
        <x-slot:badge>@if ($vehicle->plate)<x-ui.plate :value="$vehicle->plate" class="ml-1.5 align-[.15em] text-sm"/>@endif<x-ui.links :vehicle="$vehicle"/></x-slot:badge>
        {{-- Кадры блоками по стадии с глазом (показ в продаже) и «Показать все»; добавляют и удаляют — в деле ТС. --}}
        @if ($vehicle->photos()->isNotEmpty() && ! ($fromRequests ?? false))
            <x-slot:media>@include('park.vehicles.detail-photos')</x-slot:media>
        @endif
        {{-- Под названием — коды машины одним рядом (убыток с логотипом вендора, VIN; копируются нажатием, значков нет —
             `.detail-codes`) и что не так. Где, когда и сколько — полоской срока ниже (`x-park.stay`), ставки и долга у суммы нет. --}}
        <x-slot:marks>
            @if ($vehicle->ref)<x-vendor.ref :vendor="$vehicle->vendor" :ref="$vehicle->ref" copy class="tag nums"/>
            @elseif ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
            <x-ui.vin-code :vin="$vehicle->vin" class="tag"/>
            <x-park.alerts :vehicle="$vehicle" :skip="[\App\Park\Alerts::DOCS]" :place="false"/>
        </x-slot:marks>
        {{-- Главное действие карточки — одно и подвалом листа во всю ширину (10.10.2026, владелец: «кнопки действий справа
             выравнивают, а не слева»): открывает окно действия (`x-park.act-window`, 12.10.2026 — «разграничить машину и
             действие»): «Принять», «Выдать» (по QR — сканер в окне), висящий отчёт — «Отправить акт приёма» (окно сразу на
             письме) и «Отправить акт выдачи» (окно писем с черновиком). Из «Заявок» после окна — к следующей заявке. --}}
        @php
            $advance = $fromRequests ?? false;
            $releaseReport = $state === VehicleState::Released && ($releaseReportUrl ?? null);
            $foot = match (true) {
                $task && $task->type === \App\Park\RequestType::Intake && $state->isBefore() => ['intake', 'Принять'],
                $task && $task->type === \App\Park\RequestType::Release && $state === VehicleState::Stored => ['release', 'Выдать'],
                ! $task && ! $advance && $state === VehicleState::Expected => ['intake', 'Принять'],
                ! $task && ($reportDue ?? false) => ['report', 'Отправить акт приёма'],
                ! $task && ! $advance && $state === VehicleState::Stored => ['release', 'Выдать'],
                default => null,
            };
        @endphp
        @if ($foot)
            <x-slot:foot><div class="detail-action"><x-park.act-button :vehicle="$vehicle" :step="$foot[0]" :label="$foot[1]" :advance="$advance" class="w-full"/></div></x-slot:foot>
        @elseif ($releaseReport)
            <x-slot:foot><div class="detail-action"><x-mail.window-button :url="$releaseReportUrl" label="Отправить акт выдачи" icon="mail" class="btn-accent w-full"/></div></x-slot:foot>
        @elseif ($state === VehicleState::Released && ($unbilled ?? 0) > 0)
            {{-- Не выставленное хранение выданной — главной кнопкой, а не словом у суммы (09.11.2026, D3 владельца). --}}
            <x-slot:foot><div class="detail-action"><a href="/cars/{{ $vehicle->id }}/invoices/new" data-turbo-frame="_top" class="btn btn-accent w-full">Выставить счёт на {{ \App\Support\Money::rub($unbilled) }}</a></div></x-slot:foot>
        @endif
        {{-- Долг — красной строкой над полоской, как всё, что не так с машиной (09.11.2026: у суммы слов нет). --}}
        @if ($debt > 0)<div class="detail-alert mt-3">Долг {{ \App\Support\Money::rub($debt) }}</div>@endif
        <x-park.stay :vehicle="$vehicle" :total="$total" :debt="$debt" :unbilled="$unbilled ?? 0" :movable="! ($fromRequests ?? false)" class="mt-3"/>
        @if ($handedTo)<p class="mt-2 text-ink-muted">{{ $handedTo }}{{ $released && ! empty($released->payload['pass']) ? ', по QR' : ($released && ! empty($released->payload['without_qr']) ? ', без QR' : '') }}</p>@endif
        @include('park.vehicles.now')
        @if ($task)
            @include('park.vehicles.task')
        @endif
        {{-- Документы — прямо в окошке (02.11.2026, владелец: «срочно отображай документы в окошке, а то неудобно»): тот же
             список и «+ Документ», что в деле (`park.vehicles.papers`, ответ загрузки подменяет `#papers`); пусто — только
             подпись с кнопкой. --}}
        <section class="mt-3" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-collection-value="papers">
            <div class="flex items-center justify-between gap-2">
                <h2 class="detail-section">Документы @if (($n = $vehicle->papers()->count()) > 0)<span class="nums font-normal text-ink-dim">{{ $n }}</span>@endif</h2>
                <button type="button" class="btn btn-s btn-quiet shrink-0" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Документ</button>
            </div>
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-2">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.papers')
        </section>
        @if (! $task && $messages->isNotEmpty())
            {{-- Без заявки — письма ТС той же лентой, что в задаче и деле (x-mail.letters). --}}
            <section class="mt-3">
                <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ \App\Mail\Chains\NodeTitle::count($messages) }}</span></h2>
                <x-mail.letters :messages="$messages" :url="'/cars/'.$vehicle->id.'/letters'" :reply="! $state->isFinal()"/>
            </section>
        @endif
        {{-- Остальное по машине — одной группой строк, как «Настройки» iOS (20.10.2026, владелец: «симпатичнее и компактнее,
             как цены в CRM»): страхователь у заявки на приём (звонок), продажа (`x-park.sale row=bare`), расхождения с
             документами и «Заполнить из документов». Было плашкой на каждое — стопкой между письмами и заметкой. Кнопки
             расхождений — формой `take-…` вне группы: у продажи в группе шторка со своими формами. --}}
        @php
            $tel = $task && $task->type === \App\Park\RequestType::Intake && $state->isBefore() ? ($task->contact_phone ?? $vehicle->contact_phone) : null;
            $who = $task && $task->type === \App\Park\RequestType::Intake && $state->isBefore() ? rtrim((string) ($task->contact_name ?? $vehicle->contact_name), " ,") : '';
        @endphp
        @php
            // Спорные поля машины (`Cars\Sources\Variants`) — строками той же группы: полей в окошке нет.
            $variants = $state->isFinal() ? [] : \App\Cars\Sources\Variants::visible($vehicle, array_keys(\App\Cars\Sources\Values::FIELDS));
        @endphp
        <div class="list mt-3 [&:not(:has(*))]:hidden">
            @if ($tel)
                <a href="tel:+{{ preg_replace('/\D+/', '', $tel) }}" class="row">
                    <x-ui.row-icon name="phone" tone="soft" size="s"/>
                    <span class="min-w-0 flex-1"><span class="block break-words">{{ $who ?: 'Страхователь' }}</span><span class="row-sub nums text-accent-text">{{ $tel }}</span></span>
                </a>
            @elseif ($who !== '')
                <div class="row"><x-ui.row-icon name="phone" tone="muted" size="s"/><span class="min-w-0 flex-1 truncate">{{ $who }}</span></div>
            @endif
            <x-park.sale :vehicle="$vehicle" row="bare"/>
            <x-car.variants :lane="$vehicle" :variants="$variants" as="rows"/>
        </div>
        @unless ($state->isFinal())
            @if (auth()->user()->canManagePark())<span hidden data-car-doc="{{ $href }}/as-document"></span>@endif
            <x-car.variants :lane="$vehicle" :variants="$variants"/>
        @endunless
        <x-park.date-forms :vehicle="$vehicle"/>
        @if ($state === VehicleState::Stored && ! ($fromRequests ?? false))
            @include('park.vehicles.move-sheet')
        @endif

        {{-- Остальные открытые заявки ТС чипами: текущая — уже задача карточки, закрытые — в истории («Приём Выполнена»). --}}
        @php $otherRequests = $vehicle->requests->filter(fn ($r) => $r->isOpen() && ! $task?->is($r)); @endphp
        @if ($otherRequests->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($otherRequests as $r)
                    <a href="/cars/{{ $vehicle->id }}?req={{ $r->id }}" class="chip bg-accent-soft text-accent-text">{{ $r->type->label() }} <span class="nums font-normal">ждёт</span></a>
                @endforeach
            </div>
        @endif
        <form method="post" action="{{ $href }}/note" class="mt-3 flex gap-2">@csrf
            <input name="text" class="field-input field-s min-w-0 flex-1" placeholder="Заметка" required>
            <button class="btn btn-s btn-quiet shrink-0">Записать</button>
        </form>
        @php $history = \App\Cars\LaneHistory::merge($vehicle->events, \App\Cars\LaneHistory::forVehicle($vehicle))->take(5); @endphp
        @if ($history->isNotEmpty())
            <div class="mt-3 flex flex-col gap-1.5 text-sm">
                @foreach ($history as $event)
                    <div class="flex gap-3">
                        <span class="shrink-0 text-ink-dim nums">{{ \App\Support\Ago::html($event->created_at, true, words: false) }}</span>
                        <span class="min-w-0">{{ $event->text() }}</span>
                        @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}@if ($event instanceof \App\Cars\LaneEdit), {{ $event->where }}@endif</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.row-card>
</x-ui.detail>
