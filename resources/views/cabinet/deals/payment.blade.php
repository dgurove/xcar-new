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
    // Внутри задачи шага — вложенной плашкой; в «Деньгах» (`row`, 16.11.2026) — строкой карточки: подпись слева, сумма справа,
    // факт второй строкой под подписью, способы оплаты и внесённое — под строкой.
    $nested ??= false;
    $row ??= false;
    $left = $invoice ? round($invoice->remaining() - $invoice->claimed(), 2) : 0;
    $canPay = $pay && $invoice && $deal->buyer_id === auth()->id() && $invoice->state === InvoiceState::Issued && $left > 0;
    // Строка «Плательщик» — менеджеру сделки, пока она идёт: где платят (по умолчанию — где выбор способа) или где счёт ещё ждут.
    $showPayer = ($payerHere ?? $canPay) && $deal->buyer_id === auth()->id() && $deal->isActive() && ! $deal->isDirect();
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
            // Уведомление о счёте ведёт сюда с `?invoice={id}` (`MoneyNotice::url`) — к оплате этого счёта.
            request()->boolean('pay') || (int) request('invoice') === $invoice->id => array_key_first($ways),
            default => null,
        };
    }
@endphp
{{-- В задаче шага — без своей плашки (16.11.2026, владелец: «убогая вёрстка»: плашка в плашке в плашке, «Оплата по ссылке» в три
     строки): сумма и способы прямо на карточке шага. --}}
{{-- `block` (16.11.2026, вариант Б): платёж — блоком «Деньги» со своим заголовком и своей группой строк («Платит» / «Плательщик»,
     способы оплаты), блоки разделены линией — чтобы плательщик ПРАЙМ не читался плательщиком поставщика. --}}
<div class="{{ $row ? 'deal-money-row' : ($nested ? '' : 'box') }} {{ ($block ?? false) ? 'deal-money-block' : '' }} flex flex-col gap-3">
    @php $head = $href && ! $invoice ? 'a' : 'div'; @endphp
    @if ($row)
    <{{ $head }} @if ($head === 'a') href="{{ $href }}" @endif class="flex items-start gap-3">
        <span class="min-w-0 flex-1">
            <span @class(['block', 'font-medium' => $block ?? false])>{{ $title }}</span>
            @if ($state)<span @class(['block text-sm', $state[1] ?: 'text-ink-muted'])>{{ $state[0] }}</span>
            @elseif ($sub || ($invoice && $invoice->state === InvoiceState::Issued && $invoice->due_at))
                <span class="flex flex-wrap gap-x-2 text-sm text-ink-muted">@if ($sub)<span>{{ $sub }}</span>@endif @if ($invoice && $invoice->state === InvoiceState::Issued && $invoice->due_at)<x-billing.light :invoice="$invoice"/>@endif</span>
            @endif
        </span>
        <span @class(['nums shrink-0', 'text-lg font-semibold' => $strong ?? false])>{{ $amount }}</span>
    </{{ $head }}>
    @elseif ($nested)
    {{-- В задаче шага — одной строкой: кому слева, сумма справа (16.11.2026, владелец: «зачем сумму вбок забил» — подпись
         сверху и сумма отдельной строкой под ней). --}}
    <{{ $head }} @if ($head === 'a') href="{{ $href }}" @endif class="flex items-baseline gap-3">
        <span class="min-w-0 flex-1">{{ $title ?? 'К оплате' }}</span>
        <span class="nums shrink-0 text-lg font-semibold">{{ $amount }}</span>
    </{{ $head }}>
    @if ($state)<span @class(['-mt-2 block text-sm', $state[1] ?: 'text-ink-muted'])>{{ $state[0] }}</span>@endif
    @else
    <{{ $head }} @if ($head === 'a') href="{{ $href }}" @endif class="block">
        @if ($title)<span class="mb-0.5 block text-sm text-ink-muted">{{ $title }}</span>@endif
        <span class="nums block text-lg font-semibold">{{ $amount }}</span>
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
    @endif
    {{-- Что уже внесено — оплатами с платёжками, «ждёт подтверждения» (15.11.2026: было только в шторке счёта, которой больше нет). --}}
    @if ($invoice && $invoice->relationLoaded('allPayments') && $invoice->allPayments->isNotEmpty())
        <div class="list">
            @foreach ($invoice->allPayments as $p)
                <x-money.payment :payment="$p" :slip="'/account/money/invoices/'.$invoice->id.'/payments/'.$p->id.'/slip'"/>
            @endforeach
        </div>
    @endif
    {{-- Плательщик — один на счёт и ссылку (16.11.2026, владелец: «это два способа оплаты одной суммы»): первой строкой той же
         группы, что и способы оплаты, — под заголовком своего блока («ООО «ПРАЙМ»»), а не над всей карточкой: у оплаты поставщику
         свой плательщик — покупатель по договору (вариант Б, владелец: «два блока, у каждого свой плательщик»). --}}
    @if ($showPayer && ! $canPay)
        <div class="list"><x-deal.payer :deal="$deal" :action="'/deals/'.$deal->id.'/payer'" :id="'deal-payer-'.$deal->id.'-'.($invoice?->id ?? 'new')"/></div>
    @endif
    {{-- Оплата поставщику или собственнику — платит покупатель по договору (свой, не плательщик счёта ПРАЙМ): строкой «Платит»,
         нажатие — выбор покупателя (`buyer:open`, шторка «Договора»). --}}
    @if ($buyerPays ?? false)
        @php $cb = $deal->contract?->buyer; @endphp
        <div class="list">
            <x-deal.sale-row label="Плательщик" :value="$cb ? ($cb->id === $deal->buyer_id ? 'Вы' : $cb->name) : 'покупатель не выбран'" :tone="$cb ? null : 'danger'"
                data-controller="emit" data-action="emit#send" data-emit-event-param="buyer:open"/>
        </div>
    @endif
    @if ($canPay)
        <div>
            @unless ($row)<div class="list-cap !px-0">Выберите способ оплаты</div>@endunless
            <div class="list">
                @if ($showPayer)<x-deal.payer :deal="$deal" :action="'/deals/'.$deal->id.'/payer'" :id="'deal-payer-'.$deal->id.'-'.$invoice->id"/>@endif
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
            {{-- Уже заплатили переводом — платёжку сразу сюда, без шторки (16.11.2026, владелец: «подгрузку платёжки сделать
                 красивой и удобной, заколебали в чат платёжки кидать»): файл — «Я оплатил» на остаток сегодняшним днём
                 (`PayChoice`, `way=transfer`, платёжка — `slip`), дальше «ждёт подтверждения». --}}
            {{-- Та же зелёная кнопка, что у платежа поставщику (16.11.2026, владелец: «два блока оплаты по-разному оформлены, кнопки
                 приложить платёжку должны быть одинаковые, зелёная больше нравится»). --}}
            <form method="post" action="/account/money/deals/{{ $deal->id }}/pay" enctype="multipart/form-data" data-controller="autosubmit" class="mt-3">
                @csrf
                <input type="hidden" name="way" value="transfer"><input type="hidden" name="invoice" value="{{ $invoice->id }}">
                <label class="btn btn-accent btn-block cursor-pointer"><x-ui.icon name="clip" class="size-5"/> Приложить платёжку
                    <input type="file" name="slip" accept="image/*,.pdf,.heic" class="sr-only" tabindex="-1" data-action="change->autosubmit#submit">
                </label>
            </form>
            @error('slip')<p class="field-error mt-1">{{ $message }}</p>@enderror
        </div>
    @endif
</div>
