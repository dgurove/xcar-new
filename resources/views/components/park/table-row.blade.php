{{-- Строка таблицы «Наличия». Ячейка ТС в два этажа: название, под ним тусклым предупреждения словом, госномер
     и номер убытка; от 640 второй этаж встаёт в линию за названием, а номер убытка уходит в свой столбец.
     Справа сутки («216 д», одним цветом: долгая стоянка — не беда) и набежавшая сумма; ставка на телефоне под суммой, на ПК своим
     столбцом. Парковки в строке нет — она заголовком группы; place — список без групп (поиск), тогда
     парковку пишем словом. Вендор — логотипом перед названием, пока его столбца (вместе с номером убытка) не видно, — до 640. Нажатие — окошко (peek). --}}
@props(['vehicle', 'total' => null, 'debt' => 0, 'place' => false])
@php
    use App\Park\VehicleState;
    use App\Support\Money;
    $href = '/cars/'.$vehicle->id;
    $state = $vehicle->state;
    $days = $vehicle->daysStored();
    $rate = $total['rate'] ?? null;
    $amount = $total['amount'] ?? 0;
    $status = \App\Park\Status::of($vehicle);
    $hasAlerts = collect(\App\Park\Alerts::of($vehicle))->reject(fn ($a) => $a['label'] === 'Нет типа' || (! $place && $a['label'] === 'Нет парковки'))->isNotEmpty();
@endphp
<tr data-detail-key="{{ $vehicle->id }}" data-search-row id="vehicle-{{ $vehicle->id }}">
    <td class="grow">
        <x-ui.row-link :key="$vehicle->id"><span class="cell-title"><span class="cat-icon {{ $vehicle->category ? 'cat-'.$vehicle->category->value : 'cat-unknown' }}" title="{{ $vehicle->category?->label() ?? 'Тип не указан' }}"><x-ui.icon :name="$vehicle->category?->icon() ?? 'cat-unknown'" class="size-full"/></span><span class="cell-name">{{ $vehicle->titleWithYear() }}</span><x-ui.plate :value="$vehicle->plate" class="title-plate"/></span></x-ui.row-link>
        <span class="cell-sub" data-controller="fitline">
            {{-- Номера, потом ошибки данных («нет VIN», «нет тарифа»); тип не указан — знак вопроса вместо иконки, не слово.
                 Статус — своим столбцом; в краткой таблице телефона его нет, там то, что требует действия, — третьей строкой. --}}
            <span class="fit-core"><x-park.ref :vehicle="$vehicle" class="sm:hidden"/><x-ui.plate :value="$vehicle->plate" class="sub-plate"/></span>
            @if ($hasAlerts)<span class="alerts-inline"><x-park.alerts :vehicle="$vehicle" plain :place="$place" :skip="['Нет типа']"/></span>@endif
            @if ($place && $vehicle->yard)<span>{{ $vehicle->yard->name }}</span>@endif
        </span>
        {{-- Краткая таблица телефона: задача с точкой, как в столбце «Статус», и ошибки данных словами — третьей строкой. --}}
        @if ($status['act'] || $hasAlerts)
            <span class="cell-extra">
                @if ($status['act'])<span @class(['status-cell', 'is-act', 'is-late' => $status['late']])>{{ $status['label'] }}</span>@endif
                @if ($hasAlerts)<x-park.alerts :vehicle="$vehicle" plain :place="$place" :skip="['Нет типа']"/>@endif
            </span>
        @endif
    </td>
    {{-- Вендор и номер убытка одним столбцом: логотип (имя — подсказкой по наведению или нажатию), номер с копированием. --}}
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vehicle->vendor)<button type="button" class="vendor-tip" data-tip="{{ $vehicle->vendor->name }}" aria-label="{{ $vehicle->vendor->name }}"><x-vendor.logo :vendor="$vehicle->vendor"/></button>@endif @if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</span></td>
    <td class="hidden sm:table-cell"><span @class(['status-cell', 'is-act' => $status['act'], 'is-late' => $status['late']])>{{ $status['label'] }}</span></td>
    <td class="num nums col-peek-hide hidden text-ink-muted lg:table-cell">{{ $vehicle->accepted_at?->translatedFormat('j M Y') }}</td>
    <td class="num nums">
        @if ($state === VehicleState::Released && $vehicle->released_at)
            <time class="text-ink-muted" datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ $vehicle->released_at->translatedFormat('j M') }}</time>
        @elseif ($days !== null)
            <span>{{ $days }}&nbsp;д</span>
        @endif
    </td>
    <td class="num nums hidden sm:table-cell">@if ($rate){{ Money::rub($rate) }}/д@endif</td>
    <td class="num nums">
        @if ($amount > 0)<span class="block">{{ Money::rub($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-danger">долг {{ Money::nums($debt) }}</span>
        @elseif ($rate)<span class="cell-sub sm:hidden">{{ Money::rub($rate) }}/д</span>@endif
    </td>
</tr>
