{{-- Строка таблицы «Наличия». Ячейка ТС в два этажа: название, под ним тусклым предупреждения словом, госномер
     и номер убытка; от 640 второй этаж встаёт в линию за названием, а номер убытка уходит в свой столбец.
     Справа сутки («216 д», одним цветом: долгая стоянка — не беда) и набежавшая сумма; ставка на телефоне под суммой, на ПК своим
     столбцом. Парковка с местом — своим столбцом от 640 (29.10.2026). Подробная таблица — ещё и документы ТС (цвет, VIN,
     СТС, ПТС, полис, стоимость), коды копируются нажатием. Вендор — логотипом перед названием, пока его столбца (вместе с номером убытка) не видно, — до 640. Нажатие — карточка рядом.
     В «Все» выданная строка тусклая. 
     Выданная (`out`, пилюля «Выданы») — свои столбцы, архив дела: принята, выдана (под датой — сколько стояла), хранение
     за весь срок и под ним деньги словом (`OutMoney`); ставки, статуса и «что не так» у неё нет. Столбцы —
     `VehicleController::table` (выданные, деньги приёмщику), ячейки — в его порядке. Документ «Счетов и актов» — столбцы
     выданных, «Сумма» — деньги ТС в документе (`docsum`). --}}
@props(['vehicle', 'table', 'total' => null, 'debt' => 0])
@php
    use App\Park\VehicleState;
    use App\Support\Money;
    $state = $vehicle->state;
    $days = $vehicle->daysStored();
    $rate = $total['rate'] ?? null;
    $amount = $total['amount'] ?? 0;
    $status = \App\Park\Status::of($vehicle);
    // Приёмщику (27.10.2026) столбцов денег нет: ни ставки, ни начисленного, ни «хранения» у выданных — их нет в описании.
    $out = $table->has('released');
    // Документ «Счетов и актов» (06.11.2026, владелец): без госномера, парковки и суток; даты — значком приёма и выдачи
    // вместе с датой одной кнопкой-календарём (`x-park.accepted`, `x-park.date-edit` с `icon`).
    $doc = $table->has('docsum');
    $dateCls = 'inline-flex items-center gap-1.5 nums underline decoration-dotted decoration-ink-dim underline-offset-4';
    $edit = auth()->user()?->parkDates();
    $money = $out && $table->has('storage') ? \App\Park\OutMoney::of($vehicle) : null;
    // «Нет парковки» — пустой столбец и задача «Указать парковку» в «Статусе».
    $hasAlerts = ! $out && collect(\App\Park\Alerts::of($vehicle))->reject(fn ($a) => $a['label'] === 'Нет типа' || $a['label'] === 'Нет парковки')->isNotEmpty();
@endphp
{{-- `data-todo` — ТС, которой парковку указывают прямо в карточке: указали — карточка к следующей такой. --}}
<tr data-detail-key="{{ $vehicle->id }}" data-search-row id="vehicle-{{ $vehicle->id }}" @if ($state === VehicleState::Stored && ! $vehicle->yard_id) data-todo @endif @class(['is-dim' => ! $out && $state === VehicleState::Released])>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$vehicle->id"><span class="cell-title"><span class="cat-icon {{ $vehicle->category ? 'cat-'.$vehicle->category->value : 'cat-unknown' }}" title="{{ $vehicle->category?->label() ?? 'Тип не указан' }}"><x-ui.icon :name="$vehicle->category?->icon() ?? 'cat-unknown'" class="size-full"/></span><span class="cell-name">{{ $vehicle->titleWithYear() }}</span><x-car.keys :count="$vehicle->keys_count"/>@unless ($doc)<x-ui.plate :value="$vehicle->plate" class="title-plate"/>@endunless<x-ui.links :vehicle="$vehicle"/></span></x-ui.row-link>
        <span class="cell-sub" data-controller="fitline">
            {{-- Номера, потом ошибки данных («нет VIN», «нет тарифа»); тип не указан — знак вопроса вместо иконки, не слово.
                 Статус — своим столбцом; в краткой таблице телефона его нет, там то, что требует действия, — третьей строкой. --}}
            <span class="fit-core"><x-park.ref :vehicle="$vehicle" class="sm:hidden"/>@unless ($doc)<x-ui.plate :value="$vehicle->plate" class="sub-plate"/>@endunless</span>
            @if ($hasAlerts)<span class="alerts-inline"><x-park.alerts :vehicle="$vehicle" plain :place="false" :skip="['Нет типа']"/></span>@endif
            {{-- Выданная на телефоне: когда и сколько стояла — здесь, а не третьим столбцом (30.10.2026, название сжималось до «Geely…»). --}}
            @if ($doc)
                {{-- Телефон: столбцов дат нет — те же кнопки-даты здесь (свои id и формы), всегда своей строкой под номером
                     (владелец: «скачет туда-сюда», когда короткий номер пускал их в ту же строку). --}}
                <span class="mt-1 flex w-full gap-3 whitespace-nowrap sm:hidden">
                    @if ($vehicle->accepted_at)<x-park.accepted :vehicle="$vehicle" key="ph" :form="'dates-ph-'.$vehicle->id" :icon="\App\Park\RequestType::Intake" :class="$dateCls"/><form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-ph-{{ $vehicle->id }}" hidden>@csrf</form>@endif
                    @if ($vehicle->released_at)
                        @if ($edit)<x-park.date-edit :vehicle="$vehicle" field="released_at" :icon="\App\Park\RequestType::Release" :form="'dates-phr-'.$vehicle->id" :class="$dateCls" key="ph"/><form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-phr-{{ $vehicle->id }}" hidden>@csrf</form>@else<span class="inline-flex items-center gap-1 nums"><x-park.request-type :type="\App\Park\RequestType::Release" :word="false"/>{{ \App\Support\Ago::day($vehicle->released_at) }}</span>@endif
                    @endif
                    <x-park.rate :vehicle="$vehicle" key="ph"/>
                </span>
            @else
                @if ($out && $vehicle->released_at)<span class="whitespace-nowrap sm:hidden">выдана {{ \App\Support\Ago::html($vehicle->released_at) }}@if ($days !== null), {{ $days }}&nbsp;д@endif</span>@endif
                @if ($vehicle->yard)<x-ui.place class="sm:hidden">{{ $vehicle->yard->name }}</x-ui.place>@endif
            @endif
        </span>
        {{-- Краткая таблица телефона: задача с точкой, как в столбце «Статус», и значок «что не так» — третьей строкой. --}}
        @if (! $out && ($status['act'] || $hasAlerts))
            <span class="cell-extra">
                @if ($status['act'])<span @class(['status-cell', 'is-act', 'is-late' => $status['late']])>{{ $status['label'] }}</span>@endif
                @if ($hasAlerts)<x-park.alerts :vehicle="$vehicle" plain :place="false" :skip="['Нет типа']" at="phone"/>@endif
            </span>
        @endif
    </td>
        @break
        @case('yard')
    <td class="{{ $col->classes() }}">@if ($vehicle->yard)<x-ui.place>{{ $vehicle->yard->name }}@if ($vehicle->spot)<span class="text-ink-muted">, {{ $vehicle->spot }}</span>@endif</x-ui.place>@endif</td>
        @break
        @case('color')
    <td class="{{ $col->classes() }}">{{ $vehicle->color }}</td>
        @break
        @case('vin')
    <td class="{{ $col->classes() }}"><x-ui.vin-code :vin="$vehicle->vin"/></td>
        @break
        @case('sts')
    <td class="{{ $col->classes() }}"><x-ui.copy-code :value="$vehicle->sts" done="СТС в буфере"/></td>
        @break
        @case('pts')
    <td class="{{ $col->classes() }}"><x-ui.copy-code :value="$vehicle->pts" done="ПТС в буфере"/></td>
        @break
        @case('policy')
    <td class="{{ $col->classes() }}"><x-ui.copy-code :value="$vehicle->policy_no" done="Полис в буфере"/></td>
        @break
        @case('value')
    <td class="{{ $col->classes() }}">@if ($vehicle->value){{ Money::rub($vehicle->value) }}@endif</td>
        @break
        @case('vendor')
    {{-- Вендор и номер убытка одним столбцом: логотип (имя — подсказкой по наведению или нажатию), номер с копированием. --}}
    <td class="{{ $col->classes() }}"><span class="vendor-ref">@if ($vehicle->vendor)<button type="button" class="vendor-tip" data-tip="{{ $vehicle->vendor->name }}" aria-label="{{ $vehicle->vendor->name }}"><x-vendor.logo :vendor="$vehicle->vendor"/></button>@endif @if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</span></td>
        @break
        @case('status')
    {{-- «на парковке» не пишем: это и так говорит столбец «Парковка»; в «Статусе» — только дело или что-то кроме стоянки. --}}
    <td class="{{ $col->classes() }}">@if ($status['act'] || $status['label'] !== 'на парковке')<span @class(['status-cell', 'is-act' => $status['act'], 'is-late' => $status['late']])>{{ $status['label'] }}</span>@endif</td>
        @break
        @case('accepted')
    {{-- Дата приёма правится прямо в таблице (02.11.2026, владелец): нажатие — календарь с подсказками из писем, строка не
         открывается (`date#open:stop`); форма — в самой ячейке. --}}
    @if ($doc)
    <td class="{{ $col->classes() }}">@if ($vehicle->accepted_at)<x-park.accepted :vehicle="$vehicle" key="row" :form="'dates-row-'.$vehicle->id" :icon="\App\Park\RequestType::Intake" :class="$dateCls"/><form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-row-{{ $vehicle->id }}" hidden>@csrf</form>@endif</td>
    @else
    <td class="{{ $col->classes() }}">@if ($vehicle->accepted_at && auth()->user()->parkDates())<x-park.accepted :vehicle="$vehicle" key="row" :form="'dates-row-'.$vehicle->id" class="nums underline decoration-dotted decoration-ink-dim underline-offset-4"/><form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-row-{{ $vehicle->id }}" hidden>@csrf</form>@else{{ \App\Support\Ago::html($vehicle->accepted_at) }}@endif</td>
    @endif
        @break
        @case('released')
    @if ($doc)
    <td class="{{ $col->classes() }}">
        @if ($vehicle->released_at && $edit)<x-park.date-edit :vehicle="$vehicle" field="released_at" :icon="\App\Park\RequestType::Release" :form="'dates-rel-'.$vehicle->id" :class="$dateCls" key="row"/><form method="post" action="/cars/{{ $vehicle->id }}/dates" id="dates-rel-{{ $vehicle->id }}" hidden>@csrf</form>
        @elseif ($vehicle->released_at)<span class="inline-flex items-center gap-1 nums"><x-park.request-type :type="\App\Park\RequestType::Release" :word="false"/>{{ \App\Support\Ago::day($vehicle->released_at) }}</span>@endif
    </td>
    @else
    <td class="{{ $col->classes() }}">
        @if ($vehicle->released_at)<time class="block" datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ \App\Support\Ago::html($vehicle->released_at) }}</time>@endif
        @if ($days !== null)<span class="cell-sub">{{ $days }}&nbsp;д</span>@endif
    </td>
    @endif
        @break
        @case('storage')
    <td class="{{ $col->classes() }}">
        @if ($amount > 0)<span class="block">{{ Money::rub($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-urgent">не оплачено {{ Money::nums($debt) }}</span>
        @elseif ($money && ($money !== 'unbilled' || $amount > 0))<span class="cell-sub {{ \App\Park\OutMoney::TONES[$money] }}">{{ mb_strtolower(\App\Park\OutMoney::LABELS[$money]) }}</span>@endif
    </td>
        @break
        @case('docsum')
    <td class="{{ $col->classes() }}">@if ($amount > 0){{ Money::rub($amount) }}@endif</td>
        @break
        @case('days')
    <td class="{{ $col->classes() }}">
        @if ($state === VehicleState::Released && $vehicle->released_at)
            <time datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ \App\Support\Ago::html($vehicle->released_at) }}</time>
        @elseif ($days !== null)
            <span>{{ $days }}&nbsp;д</span>
        @endif
    </td>
        @break
        @case('rate')
    {{-- Своя ставка — с пометкой «своя» (12.10.2026), нет ставки — оранжевое «нет»: правят шторкой «Ставка» в окошке. --}}
    {{-- В документе «Счетов и актов» — сама шторка на месте (`x-park.rate`, владелец 06.11.2026: «ставку менять»). --}}
    @if ($doc)
    <td class="{{ $col->classes() }}"><x-park.rate :vehicle="$vehicle" key="row"/></td>
    @else
    <td class="{{ $col->classes() }}">@if ($rate){{ Money::rub($rate) }}/д@if ($vehicle->storage_rate !== null)<span class="cell-sub">своя</span>@endif @elseif ($state === VehicleState::Stored)<span class="text-urgent">нет</span>@endif</td>
    @endif
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        @if ($amount > 0)<span class="block">{{ Money::rub($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-danger">долг {{ Money::nums($debt) }}</span>
        @elseif ($rate)<span class="cell-sub sm:hidden">{{ Money::rub($rate) }}/д{{ $vehicle->storage_rate !== null ? ', своя' : '' }}</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
