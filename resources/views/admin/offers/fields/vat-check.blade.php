{{-- НДС поставщика — словами в самом заголовке «Цены» (23.11.2026, владелец: «единой надписью, не отдельно от цены: Цены с НДС 5 %,
     Цены без НДС, Цены указать НДС — красным»): заголовок карточки «Цены» редактора и окошка (`price-steps`) печатает «Цены» и
     этот кусок. С 20.11.2026 — ставкой (бухгалтер: счёт за ТС по договору комиссии — с НДС того, у кого забираем машину, на всю
     сумму); пусто — счёт без НДС. Слово — размером заголовка, системный выбор прозрачным поверх (`select_text_controller`: на
     телефоне `select` всегда 16 px, иначе iOS приближает страницу). Поле формы через `form` ($form); при смене вендора ставку
     ставит `vendor_vat_controller`. «⌄» за словом — что это выбор (владелец: «а где его указывать»). Пришла из письма или документа — серым «по письму 12.11» (`SupplierVat`). --}}
@php
    $rate = old('supplier_vat', $offer->supplier_vat);
    $unset = $rate === null || $rate === '';
    $word = fn (int $r) => $r ? 'с НДС '.\App\Billing\Vat::label($r) : 'без НДС';
    $from = match ($offer->supplier_vat_source) {
        // «как у вендора» не пишем (20.11.2026, владелец: вёрстка «Цен» разъехалась) — только откуда пришла из писем.
        'letter' => 'по письму', 'document' => 'по документу', default => null,
    };
@endphp
<span class="select-text" data-controller="select-text">
    <span class="select-text-label {{ $unset ? 'is-unset' : '' }}" data-select-text-target="label">{{ $unset ? 'указать НДС' : $word((int) $rate) }}</span><x-ui.icon name="chevron-down" class="size-3.5 shrink-0 self-center"/>
    <select name="supplier_vat" class="select-text-input" aria-label="НДС поставщика" data-select-text-target="input" data-action="change->select-text#sync" @if ($form ?? null) form="{{ $form }}" @endif>
        <option value="" data-label="указать НДС" @selected($unset)>НДС не указан</option>
        @foreach (\App\Billing\Vat::RATES as $r)
            <option value="{{ $r }}" data-label="{{ $word($r) }}" @selected(! $unset && (int) $rate === $r)>{{ $r ? 'НДС '.\App\Billing\Vat::label($r) : 'Без НДС' }}</option>
        @endforeach
    </select>
</span>
@if ($from && $offer->supplier_vat !== null)
    @if ($offer->supplier_vat_message_id)
        <button type="button" class="font-normal text-ink-dim hover:text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/offers/{{ $offer->number }}/letters?at={{ $offer->supplier_vat_message_id }}">{{ $from }}@if ($offer->supplier_vat_at) {{ $offer->supplier_vat_at->format('d.m') }}@endif</button>
    @else
        <span class="font-normal text-ink-dim">{{ $from }}</span>
    @endif
@endif
