{{-- Строка таблицы «Наличия». Ячейка ТС в два этажа: название, под ним тусклым предупреждения словом, госномер
     и номер убытка; от 640 второй этаж встаёт в линию за названием, а номер убытка уходит в свой столбец.
     Справа сутки («216 д», одним цветом: долгая стоянка — не беда) и набежавшая сумма; ставка на телефоне под суммой, на ПК своим
     столбцом. Парковка с местом — своим столбцом от 640 (29.10.2026). Подробная таблица — ещё и документы ТС (цвет, VIN,
     СТС, ПТС, полис, стоимость), коды копируются нажатием. Вендор — логотипом перед названием, пока его столбца (вместе с номером убытка) не видно, — до 640. Нажатие — карточка рядом.
     В «Все» выданная строка тусклая. 
     Выданная (`out`, пилюля «Выданы») — свои столбцы, архив дела: принята, выдана (под датой — сколько стояла), хранение
     за весь срок и под ним деньги словом (`OutMoney`); ставки, статуса и «что не так» у неё нет. Столбцы —
     `VehicleController::table` (выданные, деньги приёмщику), ячейки — в его порядке. --}}
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
    $money = $out && $table->has('storage') ? \App\Park\OutMoney::of($vehicle) : null;
    // «Нет парковки» — пустой столбец и задача «Указать парковку» в «Статусе».
    $hasAlerts = ! $out && collect(\App\Park\Alerts::of($vehicle))->reject(fn ($a) => $a['label'] === 'Нет типа' || $a['label'] === 'Нет парковки')->isNotEmpty();
@endphp
<tr data-detail-key="{{ $vehicle->id }}" data-search-row id="vehicle-{{ $vehicle->id }}" @class(['is-dim' => ! $out && $state === VehicleState::Released])>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$vehicle->id"><span class="cell-title"><span class="cat-icon {{ $vehicle->category ? 'cat-'.$vehicle->category->value : 'cat-unknown' }}" title="{{ $vehicle->category?->label() ?? 'Тип не указан' }}"><x-ui.icon :name="$vehicle->category?->icon() ?? 'cat-unknown'" class="size-full"/></span><span class="cell-name">{{ $vehicle->titleWithYear() }}</span><x-car.keys :count="$vehicle->keys_count"/><x-ui.plate :value="$vehicle->plate" class="title-plate"/><x-ui.links :vehicle="$vehicle"/></span></x-ui.row-link>
        <span class="cell-sub" data-controller="fitline">
            {{-- Номера, потом ошибки данных («нет VIN», «нет тарифа»); тип не указан — знак вопроса вместо иконки, не слово.
                 Статус — своим столбцом; в краткой таблице телефона его нет, там то, что требует действия, — третьей строкой. --}}
            <span class="fit-core"><x-park.ref :vehicle="$vehicle" class="sm:hidden"/><x-ui.plate :value="$vehicle->plate" class="sub-plate"/></span>
            @if ($hasAlerts)<span class="alerts-inline"><x-park.alerts :vehicle="$vehicle" plain :place="false" :skip="['Нет типа']"/></span>@endif
            @if ($vehicle->yard)<span class="sm:hidden">{{ $vehicle->yard->name }}</span>@endif
        </span>
        {{-- Краткая таблица телефона: задача с точкой, как в столбце «Статус», и ошибки данных словами — третьей строкой. --}}
        @if (! $out && ($status['act'] || $hasAlerts))
            <span class="cell-extra">
                @if ($status['act'])<span @class(['status-cell', 'is-act', 'is-late' => $status['late']])>{{ $status['label'] }}</span>@endif
                @if ($hasAlerts)<x-park.alerts :vehicle="$vehicle" plain :place="false" :skip="['Нет типа']"/>@endif
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
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::cell($vehicle->accepted_at) }}</td>
        @break
        @case('released')
    <td class="{{ $col->classes() }}">
        @if ($vehicle->released_at)<time class="block" datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ \App\Support\Ago::cell($vehicle->released_at) }}</time>@endif
        @if ($days !== null)<span class="cell-sub">{{ $days }}&nbsp;д</span>@endif
    </td>
        @break
        @case('storage')
    <td class="{{ $col->classes() }}">
        @if ($amount > 0)<span class="block">{{ Money::rub($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-urgent">не оплачено {{ Money::nums($debt) }}</span>
        @elseif ($money && ($money !== 'unbilled' || $amount > 0))<span class="cell-sub {{ \App\Park\OutMoney::TONES[$money] }}">{{ mb_strtolower(\App\Park\OutMoney::LABELS[$money]) }}</span>@endif
    </td>
        @break
        @case('days')
    <td class="{{ $col->classes() }}">
        @if ($state === VehicleState::Released && $vehicle->released_at)
            <time datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ \App\Support\Ago::cell($vehicle->released_at) }}</time>
        @elseif ($days !== null)
            <span>{{ $days }}&nbsp;д</span>
        @endif
    </td>
        @break
        @case('rate')
    {{-- Своя ставка — с пометкой «своя» (12.10.2026), нет ставки — оранжевое «нет»: правят шторкой «Ставка» в окошке. --}}
    <td class="{{ $col->classes() }}">@if ($rate){{ Money::rub($rate) }}/д@if ($vehicle->storage_rate !== null)<span class="cell-sub">своя</span>@endif @elseif ($state === VehicleState::Stored)<span class="text-urgent">нет</span>@endif</td>
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
