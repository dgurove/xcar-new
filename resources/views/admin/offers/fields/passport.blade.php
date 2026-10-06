{{-- Свёрнутая «Транспортное средство» (06.10.2026, владелец: свёрнутое не больше развёрнутого) — заголовок и одной строкой
     через запятую, что за машина: топливо с объёмом, мощность, коробка, цвет, пробег, город. Строка целиком —
     «развернуть» (`unhide`); VIN, номер убытка и остальное — в форме. --}}
@php
    $low = fn ($v) => $v === null || $v === '' ? null : mb_strtolower((string) $v);
    $line = implode(', ', array_filter([
        trim(($low($offer->fuel?->label()) ?? '').($offer->engine_volume ? ' '.\App\Support\Liters::format($offer->engine_volume).' л' : '')),
        $offer->engine_power ? $offer->engine_power.' л. с.' : null,
        $low($offer->transmission?->label()),
        $low($offer->color),
        $offer->mileage !== null ? \App\Support\Money::nums($offer->mileage).' км' : null,
        $offer->settlement?->title(),
    ]));
@endphp
<button type="button" class="flex w-full items-center gap-3 text-left" data-unhide-target="trigger" data-action="unhide#show">
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-medium text-ink-dim">Транспортное средство</span>
        @if ($line)<span class="mt-1 block">{{ mb_strtoupper(mb_substr($line, 0, 1)).mb_substr($line, 1) }}</span>@endif
    </span>
    <x-ui.chevron/>
</button>
