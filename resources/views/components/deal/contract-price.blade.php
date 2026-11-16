{{-- Договор нашего юрлица одной строкой «Деньги» (15.11.2026, владелец: «ты реально считаешь, что это хороший дизайн?» — сумма
     договора, НДС сегментом и «Цена подтверждения» ниже повторяли одно и то же): «Договор» и НДС словом слева, сумма справа.
     Вписанная руками (`SetContractPrice`, 14.11.2026: «всякое бывает») — вторым этажом кто, когда и сколько «в сделке».
     Нажатие — шторка: сумма крупно по центру, «Как в сделке N» чипом, НДС «Без НДС | 5 % | 22 %» (`DealContract::vatRate`,
     меняется сразу, только админ) и что будет со счётом. По счёту уже платили — сумма не правится, словом. --}}
@props(['deal', 'contract'])
@php
    use App\Offers\DealContract;
    use App\Support\Money;
    $user = auth()->user();
    $auto = DealContract::priceFor($deal);
    $editable = $deal->isActive() && $deal->moneyEditable() && $user?->canEditDeals();
    $vatEditable = $deal->isActive() && $user?->canManageCrm();
    $id = 'contract-price-'.$deal->id;
    $vat = $contract->vatRate() ? 'НДС '.$contract->vatRate().' %' : 'без НДС';
    $sub = $contract->price_manual
        ? 'вручную, '.($contract->priceAuthor?->shortName() ?? 'сотрудник').', '.\App\Support\Ago::day($contract->price_at).($auto !== null && $auto !== (int) $contract->price ? ', в сделке '.Money::rub($auto) : '')
        : ($deal->isActive() && ! $deal->moneyEditable() ? 'по счёту уже платили, сменить нельзя' : null);
    $nb = fn (string $s) => str_replace(' ', "\u{00A0}", $s);
@endphp
<div @if ($editable || $vatEditable) data-controller="sheet" @endif class="contents">
    @php ob_start(); @endphp
        {{-- Видом строки «Продажи» (16.11.2026): подпись серым слева, сумма справа, НДС и кто вписал — мелко под суммой. --}}
        <span class="sale-row-label">Сумма договора</span>
        <span class="sale-row-value"><span class="nums block">{{ $contract->price ? Money::rub($contract->price) : 'нет суммы' }}</span><span class="block text-sm text-ink-muted">{{ $vat }}@if ($sub), {{ $sub }}@endif</span></span>
    @php $line = ob_get_clean(); @endphp
    @if ($editable || $vatEditable)
        <x-ui.sheet-row class="sale-row">{!! $line !!}<x-ui.chevron/></x-ui.sheet-row>
        <x-ui.sheet :id="$id" title="Договор">
            <div class="flex flex-col gap-5">
                @if ($editable)
                    <form method="post" action="/work/deals/{{ $deal->id }}/contract-price" id="{{ $id }}-form" class="flex flex-col gap-3">
                        @csrf @method('put')
                        <label class="flex flex-col items-center gap-1">
                            <span class="flex items-baseline justify-center gap-2">
                                <input name="price" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма договора, ₽" placeholder="0"
                                       value="{{ old('price', $contract->price ? Money::nums($contract->price) : '') }}" data-controller="digits" data-action="input->digits#format">
                                <span class="text-xl text-ink-muted">₽</span>
                            </span>
                            @error('price')<span class="text-sm text-danger">{{ $message }}</span>@enderror
                        </label>
                        @if ($contract->price_manual && $auto)
                            <div class="sheet-chips justify-center">
                                <button type="submit" name="auto" value="1" class="chip nums">Как в сделке {{ Money::nums($auto) }}</button>
                            </div>
                        @endif
                    </form>
                @else
                    <p class="text-center"><span class="nums text-xl">{{ $contract->price ? Money::rub($contract->price) : 'нет суммы' }}</span>@if ($sub)<span class="block text-sm text-ink-muted">{{ $sub }}</span>@endif</p>
                @endif
                @if ($vatEditable)
                    <form method="post" action="/work/deals/{{ $deal->id }}/contract" data-controller="autosubmit" data-sheet-clean class="flex flex-col gap-1.5">
                        @csrf @method('put')
                        <span class="text-sm text-ink-muted">НДС в договоре и счёте</span>
                        <x-ui.segment name="vat_rate" :options="collect(DealContract::VAT_RATES)->mapWithKeys(fn ($r) => [$r => $r ? $nb($r.' %') : $nb('Без НДС')])->all()" :value="$contract->vatRate()" data-action="change->autosubmit#submit"/>
                    </form>
                @endif
                @if ($editable)
                    <p class="text-center text-sm text-ink-muted">В договоре и счёте {{ $deal->seller()->short() }} будет эта сумма, неоплаченный счёт перевыставится</p>
                    <x-ui.sheet-foot type="submit" form="{{ $id }}-form">Сохранить</x-ui.sheet-foot>
                @endif
            </div>
        </x-ui.sheet>
    @else
        <div class="row sale-row">{!! $line !!}<x-ui.chevron-space/></div>
    @endif
</div>
