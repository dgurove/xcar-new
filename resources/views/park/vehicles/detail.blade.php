{{-- Карточка строки таблицы машин стоянки (фрейм detail) — работа с ТС без страницы:
     лента фото, метки, дней на стоянке; у заявки — письма и телефон (`task`), приём и выдача — окном действия; переставить
     (метка парковки — кнопка шторки «Переставить»), выдать (дата, с
     подтверждением), акты; ниже расхождения карточки с документами строками «Взять» (FillFromDocs), заявки строками и
     заметка с последними событиями. Под действиями — группа документов: расхождения и «Заполнить из документов»
     (пока нет марки, модели, VIN, года или цвета) или «Сверить с документами»; искра — ещё и в полосе карточки (tools).
     Окно «Из документов» встаёт поверх, карточка остаётся и перечитывается после «Подставить».
     Формы отвечают в карточку (DetailBack), строка — свежей из row. --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $href = '/cars/'.$vehicle->id;
    $scanUrl = $href.'/scan';
    // Выданная — итог дела: кому и как выдали (событие выдачи), сколько стояла, хранение и деньги словом (OutMoney).
    $released = $state === VehicleState::Released ? $vehicle->events->firstWhere('type', \App\Park\EventType::Released) : null;
    $handedTo = $released ? ($vehicle->pickup_name ?: ($released->payload['to'] ?? null)) : null;
    $money = \App\Park\OutMoney::of($vehicle);
    $gaps = ! $vehicle->brand_id || ! $vehicle->model_id || $vehicle->vinProblem() || ! $vehicle->year || ! $vehicle->color;
@endphp
<x-ui.detail>
    {{-- Запасной «Открыть» на месте пустого ряда действий не нужен: дело открывает значок в полосе карточки. --}}
    <x-ui.row-card :href="$href" :title="$vehicle->titleWithYear()" :photos="$vehicle->visiblePhotos()" :blank="false" :action="false">
        {{-- Продажа — кнопкой на виду (11.10.2026, владелец: «куда-то делась кнопка, не можем найти» — пункт в значке связи
             не находили): строкой группы над задачей, как «Заполнить из документов»; и в «Заявках». --}}
        {{-- Состояние — словом у названия и только когда фраза его не говорит: стоит на парковке — видно по «Краснопутиловская,
             с 8 окт» (владелец 10.10.2026: «если написано, что он на Краснопутиловской с 8 октября, и так понятно»). --}}
        {{-- У названия по порядку: госномер, значок связи, состояние (12.10.2026, владелец). --}}
        <x-slot:badge>@if ($vehicle->plate)<x-ui.plate :value="$vehicle->plate" class="ml-1.5 align-[.15em] text-sm"/>@endif<x-ui.links :vehicle="$vehicle"/>@if ($state !== VehicleState::Stored)<x-ui.state :tone="$state->tone()" class="title-state">{{ mb_strtolower($state->label()) }}</x-ui.state>@endif</x-slot:badge>
        {{-- Кадры блоками по стадии с глазом (показ в продаже) и «Показать все»; добавляют и удаляют — в деле ТС. --}}
        @if ($vehicle->photos()->isNotEmpty() && ! ($fromRequests ?? false))
            <x-slot:media>@include('park.vehicles.detail-photos')</x-slot:media>
        @endif
        {{-- Под названием — машина (коды чипами: госномер, убыток с логотипом вендора, VIN) и что не так; где и с какого
             времени — фразой ниже. Владелец 10.10.2026: «одно к машине относится, другое к фактам», «статус „на
             парковке“ тут не к месту» — среди кодов; состояние — у названия). Ставки нет — дни и сумма справа. --}}
        <x-slot:marks>
            @if ($vehicle->ref)<x-vendor.ref :vendor="$vehicle->vendor" :ref="$vehicle->ref" copy class="tag nums"/>
            @elseif ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="tag"/>@endif
            <x-ui.vin-code :vin="$vehicle->vin" class="tag"/>
            <x-park.alerts :vehicle="$vehicle" :skip="$differences ? [\App\Park\Alerts::DOCS] : []"/>
            @if ($debt > 0 && $state === VehicleState::Stored)<span class="nums text-danger">долг {{ \App\Support\Money::rub($debt) }}</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($state === VehicleState::Stored && $vehicle->daysStored() !== null)<span class="nums whitespace-nowrap text-lg font-bold">{{ $vehicle->daysStored() }} {{ \App\Support\Plural::of($vehicle->daysStored(), ['день', 'дня', 'дней']) }}</span>@endif
            @if ($total && $total['amount'] > 0)<span @class(['nums whitespace-nowrap', 'text-lg font-bold' => $state === VehicleState::Released, 'text-sm text-ink-dim' => $state !== VehicleState::Released])>{{ \App\Support\Money::rub($total['amount']) }}</span>@endif
            @if ($money && ($money !== 'unbilled' || ($total['amount'] ?? 0) > 0))<span class="whitespace-nowrap text-sm {{ \App\Park\OutMoney::TONES[$money] }}">{{ mb_strtolower(\App\Park\OutMoney::LABELS[$money]) }}</span>@endif
        </x-slot:aside>
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
        @endif
        {{-- Положение фразой: «Краснопутиловская, с 6 окт, 12:00», у выданной «Измайловский лес, с 11 авг по 10 окт, 61 день»
             (состояние — у названия, у стоящей его нет). Парковка у стоящей — кнопка
             шторки «Переставить», даты — календарь администратору парковки (`x-park.date-edit`); нажимаемое — пунктиром.
             Не <p>: всплывашка календаря — <div>, и разбор HTML закрывал абзац на ней. Строка — flex: пробелы между кусками
             (компоненты кончаются переводом строки) не рисуются; свои пробелы — внутри кусков (whitespace-pre). --}}
        @php
            $at = fn ($d) => $d->translatedFormat($d->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i');
            $dotted = 'underline decoration-dotted decoration-ink-dim underline-offset-4';
            $admin = auth()->user()->isParkAdmin();
            $stored = $state === VehicleState::Stored;
            $out = $state === VehicleState::Released && $vehicle->released_at;
        @endphp
        @php
            // Куски фразы — неразрывными связками «слово, разделитель» (inline-flex: переводы строк компонентов не рисуются, свои
            // пробелы — в whitespace-pre): разделитель хвостом, перенос не начинает строку с запятой.
            $seg = 'inline-flex items-baseline whitespace-nowrap';
            $day = fn ($field) => $admin ? null : $at($vehicle->{$field});
        @endphp
        <div class="detail-where mt-2 flex flex-wrap items-baseline">
            @if ($vehicle->yard)<span class="{{ $seg }}">@if ($stored && ! ($fromRequests ?? false))<x-park.place-move :vehicle="$vehicle" :chevron="false" :class="$dotted"/>@else<x-ui.place>{{ $vehicle->yard->name }}</x-ui.place>@endif<span class="whitespace-pre text-ink-muted">{{ ($stored || $out) && $vehicle->accepted_at ? ', ' : '' }}</span></span>@endif
            @if (($stored || $out) && $vehicle->accepted_at)<span class="{{ $seg }}"><span class="whitespace-pre text-ink-muted">с </span>@if ($admin)<x-park.date-edit :vehicle="$vehicle" field="accepted_at" :class="'nums '.$dotted"/>@else<span class="nums">{{ $day('accepted_at') }}</span>@endif<span class="whitespace-pre">{{ $out ? ' ' : '' }}</span></span>@endif
            {{-- Ставка — тем же куском фразы, шторкой (12.10.2026, `x-park.rate`): «300 ₽/сут», своя, «ставки нет». --}}
            @if ($stored && ! ($fromRequests ?? false))<span class="{{ $seg }}">@if ($vehicle->yard || $vehicle->accepted_at)<span class="whitespace-pre text-ink-muted">, </span>@endif<x-park.rate :vehicle="$vehicle"/></span>@endif
            @if ($out)
                <span class="{{ $seg }}"><span class="whitespace-pre text-ink-muted">по </span>@if ($admin)<x-park.date-edit :vehicle="$vehicle" field="released_at" :class="'nums '.$dotted"/>@else<span class="nums">{{ $day('released_at') }}</span>@endif<span class="whitespace-pre text-ink-muted">{{ $vehicle->accepted_at && $vehicle->daysStored() !== null ? ', ' : '' }}</span></span>
                @if ($vehicle->accepted_at && $vehicle->daysStored() !== null)<span class="nums text-ink-muted">{{ $vehicle->daysStored() }} {{ \App\Support\Plural::of($vehicle->daysStored(), ['день', 'дня', 'дней']) }}</span>@endif
            @elseif ($state === VehicleState::Cancelled && $vehicle->cancel_reason)
                <span class="text-ink-muted">{{ $vehicle->cancel_reason }}</span>
            @endif
        </div>
        @if ($handedTo)<p class="text-ink-muted">{{ $handedTo }}{{ $released && ! empty($released->payload['pass']) ? ', по QR' : ($released && ! empty($released->payload['without_qr']) ? ', без QR' : '') }}</p>@endif
        @include('park.vehicles.now')
        @if ($task)
            @include('park.vehicles.task')
        @endif
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
        <div class="list mt-3 [&:not(:has(*))]:hidden">
            @if ($tel)
                <a href="tel:+{{ preg_replace('/\D+/', '', $tel) }}" class="row">
                    <x-ui.row-icon name="phone" tone="soft" size="s"/>
                    <span class="min-w-0 flex-1 truncate">{{ $who ?: 'Страхователь' }}</span>
                    <span class="nums shrink-0 text-accent-text">{{ $tel }}</span>
                </a>
            @elseif ($who !== '')
                <div class="row"><x-ui.row-icon name="phone" tone="muted" size="s"/><span class="min-w-0 flex-1 truncate">{{ $who }}</span></div>
            @endif
            <x-park.sale :vehicle="$vehicle" row="bare"/>
            @if ($differences || $scanFiles->isNotEmpty())
                <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" bare :form="'take-'.$vehicle->id">
                    @if ($scanFiles->isNotEmpty())<x-mail.scan-button :url="$scanUrl" :fill="$gaps" :files="$scanFiles"/>@endif
                </x-park.doc-differences>
            @endif
        </div>
        @if ($differences)<form id="take-{{ $vehicle->id }}" method="post" action="{{ $href }}/take" hidden>@csrf</form>@endif
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
                        <span class="shrink-0 text-ink-dim nums">{{ $event->created_at->translatedFormat('j M, H:i') }}</span>
                        <span class="min-w-0">{{ $event->text() }}</span>
                        @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}@if ($event instanceof \App\Cars\LaneEdit), {{ $event->where }}@endif</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
        {{-- Свежая строка списка под тем же ключом: в «Заявках» ключ — номер заявки, и строка — её (`x-park.request-row`); строка
             «Наличия» с номером ТС подменяла заявку машиной, и та переставала открываться. Закрытую заявку убирает `detail-gone`. --}}
        <x-slot:row>@if ($listed ?? null)<x-park.request-row :req="$listed" :arrive="$arrive ?? null"/>@else<x-park.table-row :vehicle="$vehicle" :total="$total"/>@endif</x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
