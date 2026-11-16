{{-- Оплата «страховой напрямую» на шаге оплаты: поставщику и ООО «ПРАЙМ» — двумя строками одной группы и одна «Приложить
     чек» (16.11.2026, владелец, вариант Б; 2611134025: под одним «Оплата» стояли две одинаковые «Приложить платёжку», менеджер
     нажал нижнюю — ПРАЙМ — и приложил чек поставщику на 2,7 млн к нашему подбору). Строка поставщика — его счёт файлом, строка
     ПРАЙМ — шторка оплаты (ссылкой, по счёту, наличными). Чек: неоплаченная одна — сразу к ней, две — шторка «За что этот чек?»
     (`receipt_controller`, `DealController::receipt`). Вход: $deal, $requirement (просьба шага оплаты), $selectionPay (счёт ПРАЙМ),
     $supplierBills (счёт поставщика файлами). --}}
@php
    use App\Support\Money;
    $bill = $supplierBills->first();
    $supplierSum = $deal->supplierPayment();
    $prime = $selectionPay;
    $primeLeft = $prime ? round($prime->remaining() - $prime->claimed(), 2) : 0;
    $primeOpen = $prime && $primeLeft > 0 && $deal->buyer_id === auth()->id();
    $primeName = $prime?->seller->config()['name'] ?? 'ООО «ПРАЙМ»';
    $primeSub = match (true) {
        ! $prime => null,
        $primeLeft <= 0 => 'ждёт подтверждения',
        default => 'счёт '.$prime->label(),
    };
    // За что может быть чек: поставщику (шаг ждёт платёжку) и ПРАЙМ (остаток не заявлен).
    $choices = array_filter([
        'supplier' => $requirement ? ['Поставщику', $bill ? 'счёт от '.$bill->created_at->timezone('Europe/Moscow')->format('d.m') : null, $supplierSum] : null,
        'invoice:'.$prime?->id => $primeOpen ? [$primeName, 'счёт '.$prime->label(), $primeLeft] : null,
    ]);
@endphp
<div class="list mt-4">
    @if ($bill)
        <a href="/files/{{ $bill->id }}" target="_blank" class="row" data-doc="{{ \App\Support\Docs::type($bill->mime_type, $bill->file_name) }}" data-doc-name="Счёт поставщика">
    @else
        <div class="row">
    @endif
        <span class="min-w-0 flex-1"><span class="block">Поставщику</span><span class="row-sub">{{ $bill ? 'счёт от '.$bill->created_at->timezone('Europe/Moscow')->format('d.m') : 'счёт пришлём сюда' }}</span></span>
        <span class="nums shrink-0 font-semibold">{{ $supplierSum ? Money::rub($supplierSum) : 'уточним' }}</span>
        @if ($bill)<x-ui.chevron/>@endif
    @if ($bill)
        </a>
    @else
        </div>
    @endif
    @if ($prime)
        <div class="contents" data-controller="sheet">
            <button type="button" class="row w-full text-left" @if ($primeOpen) data-action="sheet#open" @else disabled @endif>
                <span class="min-w-0 flex-1"><span class="block">{{ $primeName }}</span><span class="row-sub">{{ $primeSub }}</span></span>
                <span class="nums shrink-0 font-semibold">{{ Money::rub($primeOpen ? $primeLeft : $prime->remaining()) }}</span>
                @if ($primeOpen)<x-ui.chevron/>@endif
            </button>
            @if ($primeOpen)
                <x-billing.pay-sheet :id="'pay-'.$prime->id" :invoices="collect([$prime])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
                    :buyers="\App\Support\People::buyersOf(auth()->user())" :open="old('invoice') == $prime->id && $errors->any()"/>
            @endif
        </div>
    @endif
</div>
@if ($choices)
    <form method="post" action="/deals/{{ $deal->id }}/receipt" enctype="multipart/form-data" class="mt-4" data-controller="sheet receipt" data-receipt-single-value="{{ count($choices) === 1 ? 'true' : 'false' }}" data-action="receipt:ask->sheet#open">
        @csrf
        @if (count($choices) === 1)<input type="hidden" name="for" value="{{ array_key_first($choices) }}">@endif
        <label class="btn btn-accent btn-block cursor-pointer"><x-ui.icon name="clip" class="size-5"/> Приложить чек
            <input type="file" name="file" accept="image/*,.pdf,.heic" class="sr-only" tabindex="-1" data-receipt-target="input" data-action="click->receipt#reset change->receipt#picked">
        </label>
        @error('file')<p class="field-error mt-1">{{ $message }}</p>@enderror
        @error('for')<p class="field-error mt-1">{{ $message }}</p>@enderror
        @if (count($choices) > 1)
            <x-ui.sheet id="receipt-{{ $deal->id }}" title="За что этот чек?">
                <img hidden alt="" class="receipt-shot" data-receipt-target="preview">
                <p hidden class="mb-3 truncate text-sm text-ink-muted" data-receipt-target="name"></p>
                <div class="list">
                    @foreach ($choices as $key => [$name, $sub, $sum])
                        <label class="row row-check">
                            <span class="min-w-0 flex-1"><span class="block">{{ $name }}</span>@if ($sub)<span class="row-sub">{{ $sub }}</span>@endif</span>
                            <span class="nums shrink-0 font-semibold">{{ $sum ? Money::rub($sum) : '' }}</span>
                            <span class="check"><input type="radio" name="for" value="{{ $key }}" data-action="receipt#chose"></span>
                        </label>
                    @endforeach
                </div>
                <x-ui.sheet-foot type="submit" data-receipt-target="submit" disabled>Приложить</x-ui.sheet-foot>
            </x-ui.sheet>
        @endif
    </form>
@endif
