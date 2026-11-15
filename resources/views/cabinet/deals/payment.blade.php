{{-- Платёж сделки у менеджера — квитанцией с выбором способа (15.11.2026, владелец: «всем кажется, что это три разных счёта»;
     «не должно быть отдельной страницы, на которую кнопка ведёт»; «это должно быть не как счёт на оплату и оплата — кажется, что
     нужно платить и то и то, — а как ВЫБОР»). Шапка: кому и за что, сумма крупно один раз, счёт и срок одной строкой. Ниже
     «Как оплатить» — три строки, каждая открывает свою шторку уже на этом способе (`x-billing.pay-sheet pick only`):
     онлайн (кто платит, ссылка — только где ссылка возможна, `PayLink::eligible`), переводом по счёту (PDF с QR живёт здесь —
     он нужен только для перевода из банка), наличными. Оплачено или ждёт подтверждения — словом, выбора нет. Без счёта
     (платёж страховой, счёта ещё нет) — одна шапка. Вход: $title, $amount, $sub (счёт, срок) или $state [слово, тон], $invoice,
     $pay — выбор способа, $href — куда ведёт шапка без счёта (покупатель для счёта). --}}
@php
    use App\Billing\InvoiceState;
    use App\Billing\Acquiring\PayLink;
    $invoice ??= null;
    $state ??= null;
    $href ??= null;
    $pay ??= false;
    $sub ??= null;
    // Подпись «кому» — только когда платежей несколько и их надо различить (15.11.2026, владелец: «„ПРАЙМ, за автомобиль“ —
    // нейрослоп, реквизиты и так в счёте»); номер счёта — только в самом файле.
    $title ??= null;
    // Внутри задачи шага — вложенной плашкой, в «Платежах» — своей карточкой.
    $nested ??= false;
    $left = $invoice ? round($invoice->remaining() - $invoice->claimed(), 2) : 0;
    $canPay = $pay && $invoice && $deal->buyer_id === auth()->id() && $invoice->state === InvoiceState::Issued && $left > 0;
    if ($canPay) {
        $online = app(\App\Billing\Acquiring\Gateway::class)->configured() && PayLink::eligible($invoice);
        $ways = array_filter([
            'link' => $online ? ['sbp', 'Онлайн', 'СБП, SberPay или картой'] : null,
            // Перевод — это и есть счёт (15.11.2026, владелец: «просто наша иконка PDF и „Счёт № 170“»): значок файла с весом.
            'transfer' => ['file', 'Счёт '.$invoice->label(), null],
            'cash' => ['cash', 'Наличными', null],
        ]);
        $buyers = \App\Support\People::buyersOf(auth()->user());
        $link = $invoice->openLink();
        // `?pay=1`, ошибка формы и только что заведённая ссылка — открыть ту шторку, где это было.
        $openWay = match (true) {
            $errors->any() && old('way') && old('invoice') == $invoice->id => old('way'),
            $link && session('open-link') === $link->id => 'link',
            request()->boolean('pay') => array_key_first($ways),
            default => null,
        };
    }
@endphp
<div class="{{ $nested ? 'box-nested' : 'box' }} flex flex-col gap-3">
    @php $head = $href && ! $invoice ? 'a' : 'div'; @endphp
    <{{ $head }} @if ($head === 'a') href="{{ $href }}" @endif class="block">
        @if ($title)<span class="mb-0.5 block text-sm text-ink-muted">{{ $title }}</span>@endif
        <span class="nums block text-2xl font-semibold">{{ $amount }}</span>
        @if ($state)
            <span @class(['mt-1 block text-sm', $state[1] ?: 'text-ink-muted'])>{{ $state[0] }}</span>
        {{-- Срок счёта — только в «Платежах»: в задаче шага срок уже в её заголовке («Просрочено на 4д 20ч»), второй был бы
             тем же, да ещё с другой цифрой. --}}
        {{-- Срок — только настоящей датой: без неё `x-billing.light` пишет «вместе с оплатой за ТС» / «после договора» —
             менеджеру с этим нечего делать (15.11.2026, владелец: «что это вообще значит?»). --}}
        @elseif ($sub || ($invoice && $invoice->state === InvoiceState::Issued && $invoice->due_at && ! $nested))
            <span class="mt-1 flex flex-wrap gap-x-2 text-sm text-ink-muted">@if ($sub)<span>{{ $sub }}</span>@endif @if ($invoice && $invoice->state === InvoiceState::Issued && $invoice->due_at && ! $nested)<x-billing.light :invoice="$invoice"/>@endif</span>
        @endif
    </{{ $head }}>
    @if ($canPay)
        <div>
            <div class="list-cap !px-0">Выберите способ оплаты</div>
            <div @class(['list', 'bg-surface' => $nested])>
                @foreach ($ways as $way => [$icon, $label, $hint])
                    <div class="contents" data-controller="sheet">
                        <button type="button" class="row w-full text-left" data-action="sheet#open">
                            @if ($icon === 'file')<x-ui.file-icon name="schet.pdf" :bytes="$invoice->getFirstMedia('file')?->size"/>@else<x-ui.row-icon :name="$icon" size="s"/>@endif
                            <span class="min-w-0 flex-1"><span class="block">{{ $label }}</span>@if ($hint)<span class="row-sub">{{ $hint }}</span>@endif</span>
                            <x-ui.chevron/>
                        </button>
                        <x-billing.pay-sheet :id="'pay-'.$invoice->id.'-'.$way" :pick="$way" only :invoices="collect([$invoice])" :action="'/account/money/deals/'.$deal->id.'/pay'" pdf="/account/invoices/{id}/pdf"
                            :buyers="$buyers" :open="$openWay === $way"/>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
