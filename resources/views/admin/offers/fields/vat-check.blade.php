{{-- НДС поставщика — у заголовка «Цены» (19.10.2026, владелец: «это же всех цен касается»): окошко строки (`price-steps`) и
     карточка «Цены» редактора. С 20.11.2026 — ставкой, а не галкой «С НДС» (бухгалтер: счёт за ТС по договору комиссии — с НДС
     того, у кого забираем машину, на всю сумму): «НДС поставщика: Без НДС ⌄», пусто — «не указан» (счёт тогда без НДС). Поле формы
     через `form` ($form); при смене вендора ставку ставит `vendor_vat_controller`. Пришла из письма или документа — серым
     «по письму 12.11» (`SupplierVat`). --}}
@php
    $rate = old('supplier_vat', $offer->supplier_vat);
    $from = match ($offer->supplier_vat_source) {
        // «как у вендора» не пишем (20.11.2026, владелец: вёрстка «Цен» разъехалась) — только откуда пришла из писем.
        'letter' => 'по письму', 'document' => 'по документу', default => null,
    };
@endphp
<span class="flex min-w-0 flex-wrap items-center justify-end gap-x-1.5 text-sm text-ink-muted">
    <label class="whitespace-nowrap" for="supplier-vat-{{ $offer->id }}-{{ $form ?? 'f' }}">НДС поставщика</label>
    <select id="supplier-vat-{{ $offer->id }}-{{ $form ?? 'f' }}" name="supplier_vat" class="keep-hours text-ink" @if ($form ?? null) form="{{ $form }}" @endif>
        <option value="" @selected($rate === null || $rate === '')>не указан</option>
        @foreach (\App\Billing\Vat::RATES as $r)
            <option value="{{ $r }}" @selected($rate !== null && $rate !== '' && (int) $rate === $r)>{{ \App\Billing\Vat::label($r) }}</option>
        @endforeach
    </select>
    @if ($from && $offer->supplier_vat !== null)
        @if ($offer->supplier_vat_message_id)
            <button type="button" class="text-ink-dim hover:text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $offer->number }}/letters?at={{ $offer->supplier_vat_message_id }}">{{ $from }}@if ($offer->supplier_vat_at) {{ $offer->supplier_vat_at->format('d.m') }}@endif</button>
        @else
            <span class="text-ink-dim">{{ $from }}</span>
        @endif
    @endif
</span>
