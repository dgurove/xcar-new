{{-- «Транспортное средство» текстом (06.10.2026, «Изменить / Готово», как в Контактах iOS): та же сетка и подписи, что
     у полей (`fields.car`), вместо полей — значения; пустые не показываются. «Изменить» в заголовке карточки ставит на это
     место поля (`edit_card_controller`). --}}
@php
    $low = fn ($v) => $v === null || $v === '' ? null : $v;
    $cells = array_filter([
        'Вендор' => $offer->vendor?->name,
        ($offer->leaseRef() ? 'Номер ДЛ' : 'Номер убытка') => $offer->claim_ref,
        'VIN' => $offer->vin,
        'Марка' => $offer->brand?->name,
        'Модель' => $offer->model?->name,
        'Год' => $offer->year,
        'Пробег' => $offer->mileage !== null ? \App\Support\Money::nums($offer->mileage).' км' : null,
        'Цвет' => $low($offer->color),
        'Кузов' => $offer->body?->label(),
        'Коробка' => $offer->transmission?->label(),
        'Привод' => $offer->drive?->label(),
        'Топливо' => $offer->fuel?->label(),
        'Объём' => $offer->engine_volume ? \App\Support\Liters::format($offer->engine_volume).' л' : null,
        'Мощность' => $offer->engine_power ? $offer->engine_power.' л. с.' : null,
        'Город' => $offer->settlement?->title(),
        'Адрес осмотра' => $low($offer->inspection_address),
    ], fn ($v) => $v !== null && $v !== '');
    $description = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</div>', '</p>'], "\n", (string) $offer->description))));
@endphp
<div class="{{ $grid }}">
    @foreach ($cells as $label => $value)
        <div @class(['min-w-0', 'col-span-2' => $label === 'Адрес осмотра' || $label === 'VIN'])>
            <div class="field-label">{{ $label }}</div>
            <div class="mt-1 break-words">
                @if ($label === 'VIN')<x-ui.vin-code :vin="$value" copy/>
                @elseif ($label === 'Город')<x-ui.place>{{ $value }}</x-ui.place>
                @else{{ $value }}@endif
            </div>
        </div>
    @endforeach
    @if ($description !== '')
        <div class="col-span-full min-w-0">
            <div class="field-label">Описание</div>
            <p class="mt-1 line-clamp-4 whitespace-pre-line text-ink-muted">{{ $description }}</p>
        </div>
    @endif
</div>
