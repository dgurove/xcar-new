{{-- Сумма договора строкой блока «Кто продаёт» (14.11.2026, владелец: «админам и модераторам вручную удобно красиво менять
     сумму договора и счёта, всякое бывает»; `SetContractPrice`). Строка — сумма справа, вписанная руками — вторым этажом кто
     и когда и сколько «в сделке»; нажатие — шторка: сумма крупно по центру, как у контрпредложения, «Как в сделке N»
     чипом, что будет со счётом. По счёту уже платили — строка без шторки, словом. Только у договора нашего юрлица. --}}
@props(['deal', 'contract'])
@php
    use App\Offers\DealContract;
    use App\Support\Money;
    $user = auth()->user();
    $auto = DealContract::priceFor($deal);
    $editable = $deal->isActive() && $deal->moneyEditable() && $user?->canEditDeals();
    $id = 'contract-price-'.$deal->id;
    $sub = $contract->price_manual
        ? 'вручную, '.($contract->priceAuthor?->shortName() ?? 'сотрудник').', '.\App\Support\Ago::day($contract->price_at).($auto !== null && $auto !== (int) $contract->price ? ', в сделке '.Money::rub($auto) : '')
        : ($deal->isActive() && ! $deal->moneyEditable() ? 'по счёту уже платили, сменить нельзя' : null);
@endphp
<div @if ($editable) data-controller="sheet" @endif class="contents">
    @if ($editable)
        <x-ui.sheet-row>
            <span class="min-w-0 flex-1">
                <span class="block">Сумма договора</span>
                @if ($sub)<span class="row-sub">{{ $sub }}</span>@endif
            </span>
            <span class="nums shrink-0">{{ $contract->price ? Money::rub($contract->price) : 'нет' }}</span>
            <x-ui.chevron/>
        </x-ui.sheet-row>
        <x-ui.sheet :id="$id" title="Сумма договора">
            <form method="post" action="/work/deals/{{ $deal->id }}/contract-price" class="flex flex-col gap-4">
                @csrf @method('put')
                <label class="flex flex-col items-center gap-1">
                    <span class="flex items-baseline justify-center gap-2">
                        <input name="price" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма договора, ₽" placeholder="0"
                               value="{{ old('price', $contract->price ? Money::nums($contract->price) : '') }}" data-controller="digits" data-action="input->digits#format" autofocus>
                        <span class="text-xl text-ink-muted">₽</span>
                    </span>
                    @error('price')<span class="text-sm text-danger">{{ $message }}</span>@enderror
                </label>
                @if ($contract->price_manual && $auto)
                    <div class="sheet-chips justify-center">
                        <button type="submit" name="auto" value="1" class="chip nums">Как в сделке {{ Money::nums($auto) }}</button>
                    </div>
                @endif
                <p class="text-center text-sm text-ink-muted">В договоре и счёте {{ $deal->seller()->short() }} будет эта сумма, неоплаченный счёт перевыставится</p>
                <x-ui.sheet-foot type="submit">Сохранить</x-ui.sheet-foot>
            </form>
        </x-ui.sheet>
    @else
        <div class="row">
            <span class="min-w-0 flex-1">
                <span class="block">Сумма договора</span>
                @if ($sub)<span class="row-sub">{{ $sub }}</span>@endif
            </span>
            <span class="nums shrink-0">{{ $contract->price ? Money::rub($contract->price) : 'нет' }}</span>
        </div>
    @endif
</div>
