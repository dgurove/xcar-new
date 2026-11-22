{{-- Данные машины сводкой — под пачками фото в правой колонке страницы машины CRM (23.11.2026, владелец: в сделке «в 99 %
     не трогают детали машины», «компактно справа в блоке, где фотки»; из макетов выбрал «сетку»): подпись и значение в два
     столбца, пустое не рисуется; коды (№, убыток с логотипом вендора, VIN, госномер, ПТС, СТС, полис) — сюда из шапки,
     копируются нажатием. Марка, модель и год — в названии страницы. Правка — «Изменить»: шторка «Данные ТС»
     (`admin.offers.car-sheet`, id `sheet`) со всеми полями. Спорное поле — «или …» под значением, нажатие — та же шторка
     (шторки вариантов живут в ней). --}}
@props(['offer', 'sheet'])
@php
    use App\Cars\CarFields;
    $car = $offer->carRecord();
    $show = fn (string $c) => CarFields::show($c, $car?->{$c});
    // Части одной ячейки через запятую: первая как есть, остальные со строчной («Хэтчбек, серый»).
    $join = fn (array $parts, string $sep = ', ') => ($parts = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== '')))
        ? implode($sep, [$parts[0], ...array_map(fn ($p) => mb_strtolower(mb_substr($p, 0, 1)).mb_substr($p, 1), array_slice($parts, 1))]) : null;
    $hints = \App\Cars\Sources\Variants::visible($offer, ['car', 'year', 'vin', 'color', 'mileage', 'plate', 'pts', 'sts', 'body', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'city']);
    $hint = fn (string ...$fields) => collect($fields)->map(fn ($f) => $hints[$f] ?? null)->filter()->first();
    $number = \App\Offers\OfferNumber::isPublic($offer) ? (string) $offer->number : null;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $pts = $car?->pts;
    $engine = $join([$join([$show('fuel'), $show('engine_volume')], ' '), $show('engine_power')]);
@endphp
<div class="car-summary-head">
    <h2 class="box-title">Транспортное средство</h2>
    <button type="button" class="edit-card-toggle" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">Изменить</button>
</div>
<dl class="car-summary">
    @if ($hint('car', 'year'))
        <div class="col-span-2"><dt>Марка, модель</dt><dd>{{ $offer->titleWithYear() }}</dd><x-car.summary-hint :v="$hint('car', 'year')" :sheet="$sheet"/></div>
    @endif
    @if ($number)<div><dt>Номер</dt><dd><x-ui.copy-code :value="$number" done="Номер в буфере"/></dd></div>@endif
    @if ($offer->claim_ref)
        <div><dt>{{ $offer->leaseRef() ? '№ ДЛ' : '№ убытка' }}</dt><dd><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" copy/></dd></div>
    @elseif ($vendor)
        <div><dt>Вендор</dt><dd><x-vendor.name :vendor="$vendor"/></dd></div>
    @endif
    @if ($car?->vin || $hint('vin'))<div><dt>VIN</dt><dd><x-ui.vin-code :vin="$car?->vin"/></dd><x-car.summary-hint :v="$hint('vin')" :sheet="$sheet"/></div>@endif
    @if ($car?->plate || $hint('plate'))<div><dt>Госномер</dt><dd><x-ui.copy-code :value="$car?->plate"/></dd><x-car.summary-hint :v="$hint('plate')" :sheet="$sheet"/></div>@endif
    @if ($pts || $hint('pts'))
        <div><dt>{{ preg_match('/^\d{15}$/', preg_replace('/\s+/', '', (string) $pts)) ? 'ЭПТС' : 'ПТС' }}</dt><dd><x-ui.copy-code :value="$pts"/></dd><x-car.summary-hint :v="$hint('pts')" :sheet="$sheet"/></div>
    @endif
    @if ($car?->sts || $hint('sts'))<div><dt>СТС</dt><dd><x-ui.copy-code :value="$car?->sts"/></dd><x-car.summary-hint :v="$hint('sts')" :sheet="$sheet"/></div>@endif
    @if ($car?->policy_no)<div><dt>Полис</dt><dd><x-ui.copy-code :value="$car->policy_no"/></dd></div>@endif
    @if (($body = $join([$show('body'), $show('color')])) || $hint('body', 'color'))
        <div><dt>Кузов, цвет</dt><dd>{{ $body }}</dd><x-car.summary-hint :v="$hint('body', 'color')" :sheet="$sheet"/></div>
    @endif
    @if ($engine || $hint('fuel', 'engine_volume', 'engine_power'))
        <div><dt>Двигатель</dt><dd>{{ $engine }}</dd><x-car.summary-hint :v="$hint('fuel', 'engine_volume', 'engine_power')" :sheet="$sheet"/></div>
    @endif
    @if (($gear = $join([$show('transmission'), $show('drive')])) || $hint('transmission', 'drive'))
        <div><dt>КПП, привод</dt><dd>{{ $gear }}</dd><x-car.summary-hint :v="$hint('transmission', 'drive')" :sheet="$sheet"/></div>
    @endif
    @if (($km = $show('mileage')) || $hint('mileage'))<div><dt>Пробег</dt><dd>{{ $km }}</dd><x-car.summary-hint :v="$hint('mileage')" :sheet="$sheet"/></div>@endif
    @if ($offer->settlement || $hint('city'))
        <div><dt>Город</dt><dd>@if ($offer->settlement)<x-ui.place>{{ $offer->settlement->title() }}</x-ui.place>@endif</dd><x-car.summary-hint :v="$hint('city')" :sheet="$sheet"/></div>
    @endif
    @if ($offer->sellsElsewhere())<div><dt>Город продажи</dt><dd><x-ui.place>{{ $offer->saleSettlement?->title() }}</x-ui.place></dd></div>@endif
</dl>
