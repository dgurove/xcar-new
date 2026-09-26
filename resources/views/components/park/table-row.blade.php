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
@endphp
<tr id="vehicle-{{ $vehicle->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title"><span class="cat-icon {{ $vehicle->category ? 'cat-'.$vehicle->category->value : '' }}" @if ($vehicle->category) title="{{ $vehicle->category->label() }}" @endif>@if ($vehicle->category)<x-ui.icon :name="$vehicle->category->icon()" class="size-full"/>@endif</span>@if ($vehicle->vendor)<x-vendor.logo :vendor="$vehicle->vendor" class="col-vendor-lead mr-[.25em]"/>@endif{{ $vehicle->titleWithYear() }}</span>
        <span class="cell-sub">
            {{-- Продана и ждёт выдачи, по делу ждут нашего ответа — чипами первыми: это то, что надо сделать сегодня. --}}
            @if ($state === VehicleState::Stored && $vehicle->sold_at)<span class="tag tag-accent">продана</span>@endif
            @if ($vehicle->waiting_count ?? 0)<span class="tag tag-urgent">ждёт ответа</span>@endif
            @if ($state !== VehicleState::Stored)<span>{{ mb_strtolower($state->label()) }}</span>@endif
            <x-park.alerts :vehicle="$vehicle" plain :place="$place"/>
            @if ($vehicle->plate)<x-ui.plate :value="$vehicle->plate"/>@endif
            @if ($vehicle->ref)<span class="sm:hidden">{{ $vehicle->ref }}</span>@endif
            @if ($place && $vehicle->yard)<span>{{ $vehicle->yard->name }}</span>@endif
        </span>
    </td>
    {{-- Вендор и номер убытка одним столбцом: логотип (имя — подсказкой по наведению или нажатию), номер с копированием. --}}
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vehicle->vendor)<button type="button" class="vendor-tip" data-tip="{{ $vehicle->vendor->name }}" aria-label="{{ $vehicle->vendor->name }}"><x-vendor.logo :vendor="$vehicle->vendor"/></button>@endif @if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</span></td>
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
