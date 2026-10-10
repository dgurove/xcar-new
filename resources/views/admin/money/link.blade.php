{{-- Карточка ссылки на оплату — строка «По ссылкам» (09.10.2026, владелец: «должно чувствоваться как приложение»), как
     запрос на оплату в банке:
     — сумма крупно по центру, под ней что со ссылкой словом и кто платит (ТС — строкой ниже, один раз);
     — круглые кнопки с подписью: «Отправить» (лайм; без системного листа на ПК лайм у «Скопировать»), «Скопировать»
       (текст «ФИО, ТС, сумма» и адрес, `PayLink::message`), «QR-код»; у оплаченной — «Вернуть», если есть что;
     — строки: ТС (у ручной — шторка с полем «ТС» и подсказкой машин, `x-billing.car-field`), сделка или гараж и что с
       зачётом, счёт, чек;
     — история лентой: создал, что делал плательщик, оплачено или отменена;
     — «Отменить ссылку» красной строкой внизу. В «···» — только «Другая сумма». --}}
@php
    use App\Support\Money; use App\Http\Admin\MoneyLinks as L; use App\Billing\Acquiring\PayLinkState; use App\Billing\Acquiring\PayMethod; use App\Billing\InvoiceState;
    $i = $invoice;
    $id = $link->id;
    $live = $link->isOpen();
    $manual = $link->origin() === 'manual';
    $offer = $i->carOffer();
    $car = $i->carLine();
    [$state, $tone] = L::state($link);
    $paidAttempt = $link->state === PayLinkState::Paid ? $link->attempts->firstWhere('payment_id', $link->payment_id) : null;
    if ($paidAttempt?->method) { $state = 'оплачено '.mb_strtolower(PayMethod::label($paidAttempt->method)).' '.$link->paid_at->translatedFormat('j M'); }
    $deal = $i->deal ?? $i->creditDeal;
    $dealUrl = $deal?->offer ? '/offers/'.$deal->offer->number : null;
    $by = L::creator($link);
    $refund = $link->attempts->first(fn ($a) => $a->succeeded() && $a->refundable() > 0);
    $editCar = $manual && $i->state !== InvoiceState::Void;
    $other = $live && $i->state === InvoiceState::Issued;
    // Засчитано в сделку и сверх неё (09.10.2026, владелец: сверх — «просто доплата», не красным).
    $applied = $i->creditDeal ? app(\App\Offers\Actions\ApplyDealCredits::class)->applied($i) : 0;
    $creditWord = match (true) {
        ! $i->creditDeal => null,
        $i->paid <= 0 => 'засчитаем, когда оплатят',
        \App\Offers\DealCredit::room($i->creditDeal) === null && $applied <= 0 => 'засчитаем, когда выставим счёт',
        $applied >= $i->paid - 0.005 => 'в зачёт '.Money::rub($applied),
        $applied > 0 => 'в зачёт '.Money::rub($applied).', сверх '.Money::rub($i->paid - $applied),
        default => 'сверх сделки '.Money::rub($i->paid),
    };
    $open = fn (string $name) => 'data-controller="emit" data-action="emit#send" data-emit-event-param="link-'.$id.'-'.$name.':open"';
    // Время без часов (оплату отметили датой) — только день, а не «00:00».
    $at = fn ($t) => $t->translatedFormat(match (true) { $t->format('H:i:s') === '00:00:00' => 'j M', $t->isToday() => 'H:i', default => 'j M, H:i' });
    // История: создал, попытки плательщика, оплачено или отменена — по времени.
    $history = collect([['created' => $link->created_at, 'text' => match (true) { (bool) $by => 'Создал '.$by->shortName(), $link->origin() === 'deal' => 'Создана сделкой', $link->origin() === 'garage' => 'Создана гаражом', default => 'Создана вручную' }, 'tone' => null]]);
    foreach ($link->attempts as $a) {
        [$what, $aTone] = match (true) {
            $a->succeeded() => ['Оплатили', 'open'],
            $a->isPending() => ['Открыли оплату', 'urgent'],
            $a->cancel_reason === 'expired_on_confirmation' => ['Открыли и не оплатили', 'urgent'],
            default => ['Не прошла: '.($a->cancelLabel() ?? 'отклонено'), 'danger'],
        };
        $history->push(['created' => $a->created_at, 'text' => $what.($a->method ? ', '.mb_strtolower(PayMethod::label($a->method)) : ''), 'tone' => $aTone]);
    }
    if ($link->state === PayLinkState::Paid && ! $paidAttempt) { $history->push(['created' => $link->paid_at, 'text' => 'Оплачено', 'tone' => 'open']); }
    if ($link->state === PayLinkState::Canceled && $link->canceled_at) { $history->push(['created' => $link->canceled_at, 'text' => 'Отменена', 'tone' => null]); }
    if ($link->error_at) { $history->push(['created' => $link->error_at, 'text' => 'ЮKassa не открыла оплату', 'tone' => 'danger']); }
    $history = $history->sortBy('created')->values();
@endphp
<x-ui.detail>
    <x-ui.row-card :title="$link->payerName()" :head="false" :photo="false" :action="false">
        @if ($other)
            <x-slot:tools>
                <div class="contents" data-controller="menu">
                    <button type="button" class="bar-btn" data-action="menu#toggle" aria-haspopup="menu" aria-controls="link-more-{{ $id }}" aria-label="Ещё"><x-ui.icon name="more" class="size-[18px]"/></button>
                    <div id="link-more-{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="menu#close emit#send" data-emit-event-param="link-{{ $id }}-amount:open">Другая сумма</button>
                    </div>
                </div>
            </x-slot:tools>
        @endif

        <div class="money-hero">
            <div class="money-hero-sum nums">{{ Money::nums($link->amount, fmod($link->amount, 1) ? 2 : 0) }}<small> ₽</small></div>
            <div class="money-hero-state {{ $tone ?: 'text-ink-muted' }}">{{ $state }}</div>
            <div class="money-hero-who">{{ $link->payerName() }}</div>
        </div>

        @if ($live)
            <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $link->url() }}" data-copy-title-value="Оплата по ссылке">
                <textarea hidden data-copy-target="message">{{ $link->message() }}</textarea>
                <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
                <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
                <button type="button" class="act" {!! $open('qr') !!}><span class="btn btn-quiet btn-round"><x-ui.icon name="qr"/></span>QR-код</button>
            </div>
        @elseif ($refund)
            <div class="acts acts-hero">
                <form method="post" action="/work/money/acquiring/{{ $refund->id }}/refund" class="contents" data-turbo-confirm="Вернуть {{ Money::exact($refund->refundable()) }} плательщику? Оплата по счёту отменится">
                    @csrf<button class="act"><span class="btn btn-danger btn-round"><x-ui.icon name="undo"/></span>Вернуть</button>
                </form>
            </div>
        @endif

        <div class="list mt-6">
            @if ($editCar)
                <button type="button" class="row w-full text-left" {!! $open('car') !!}>
                    <span class="shrink-0 text-ink-muted">ТС</span>
                    <span @class(['min-w-0 flex-1 truncate text-right', 'text-accent-text' => ! $car])>{{ $car ?? 'Указать' }}</span>
                    <x-ui.chevron/>
                </button>
            @elseif ($car)
                <a @if ($offer) href="/offers/{{ $offer->number }}" data-turbo-frame="_top" @endif class="row">
                    <span class="shrink-0 text-ink-muted">ТС</span>
                    <span class="min-w-0 flex-1 truncate text-right nums">{{ $car }}</span>
                    @if ($offer)<x-ui.chevron/>@endif
                </a>
            @endif
            @if ($manual && $i->creditDeal)
                <a @if ($dealUrl) href="{{ $dealUrl }}" data-turbo-frame="_top" @endif class="row">
                    <span class="shrink-0 text-ink-muted">Сделка</span>
                    <span class="min-w-0 flex-1 text-right">
                        <span class="block truncate">{{ $i->creditDeal->buyer?->shortName() ?? 'есть' }}</span>
                        @if ($creditWord)<span class="block text-sm text-ink-muted">{{ $creditWord }}</span>@endif
                    </span>
                    @if ($dealUrl)<x-ui.chevron/>@endif
                </a>
            @elseif (! $manual)
                <a @if ($dealUrl) href="{{ $dealUrl }}" @elseif ($offer) href="/work/garage?preset=all&peek={{ $offer->number }}" @endif class="row" data-turbo-frame="_top">
                    <span class="shrink-0 text-ink-muted">{{ $link->origin() === 'deal' ? 'Сделка' : 'Гараж' }}</span>
                    <span class="min-w-0 flex-1 truncate text-right nums">{{ $i->manager()?->shortName() ?? ($offer ? '№ '.$offer->number : '') }}</span>
                    <x-ui.chevron/>
                </a>
            @endif
            <a href="/work/money/invoices/{{ $i->id }}" class="row" data-turbo-frame="_top">
                <span class="shrink-0 text-ink-muted">Счёт</span>
                <span class="min-w-0 flex-1 truncate text-right nums">{{ $i->label() }}@if ($i->issued_at) от {{ $i->issued_at->translatedFormat('j M') }}@endif</span>
                <x-ui.chevron/>
            </a>
            @if ($link->payer_email)
                <div class="row"><span class="shrink-0 text-ink-muted">Чек</span><span class="min-w-0 flex-1 truncate text-right">{{ $link->payer_email }}</span></div>
            @endif
        </div>

        <div class="list-cap mt-5">История</div>
        <div class="timeline">
            @foreach ($history as $h)
                <div @class(['timeline-item', 'timeline-'.$h['tone'] => $h['tone']])><span>{{ $h['text'] }}</span><span class="nums">{{ $at($h['created']) }}</span></div>
            @endforeach
        </div>

        @if ($live)
            <form method="post" action="/work/money/links/{{ $id }}" class="list mt-5" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">
                @csrf @method('delete')
                <button class="row row-danger">Отменить ссылку</button>
            </form>
        @endif

        @if ($live)
            <div data-controller="sheet" data-action="link-{{ $id }}-qr:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'link-qr-'.$id" title="QR-код">
                    <x-billing.pay-qr :link="$link" size="w-56"/>
                </x-ui.sheet>
            </div>
        @endif
        @if ($other)
            <div data-controller="sheet" data-action="link-{{ $id }}-amount:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'link-amount-'.$id" title="Другая сумма">
                    {{-- Ручная перевыставляется новым счётом (`ReissueServiceInvoice`) — открывается уже новая ссылка. --}}
                    <form method="post" action="/work/money/invoices/{{ $i->id }}/links" class="flex flex-col gap-4" @if ($manual) data-turbo-frame="_top" @endif>
                        @csrf
                        <label class="flex items-baseline justify-center gap-2">
                            <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0"
                                value="{{ Money::nums($link->amount, fmod($link->amount, 1) ? 2 : 0) }}" data-controller="digits" data-action="input->digits#format">
                            <span class="text-2xl text-ink-muted">₽</span>
                        </label>
                        @error('amount')<div class="text-center text-sm text-danger">{{ $message }}</div>@enderror
                        <div class="sheet-foot"><x-ui.button block>Новая ссылка на эту сумму</x-ui.button></div>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
        @if ($editCar)
            {{-- Машина и есть сделка (владелец 09.10.2026): поле «ТС» с подсказкой машин системы (`x-billing.car-field`), у ТС со
                 сделкой оплата идёт в её зачёт — исход на кнопке и строкой под полем (`DealCredit::outcome`). Поле ставит
                 палец (без autofocus): программный фокус iOS сдвигал лист. --}}
            <div data-controller="sheet" data-action="link-{{ $id }}-car:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'link-car-'.$id" title="ТС" class="sheet-steady">
                    <form method="post" action="/work/money/links/{{ $id }}/car" class="flex flex-1 flex-col gap-3">
                        @csrf @method('put')
                        <x-billing.car-field group :offer-id="$i->offer_id" :text="$i->offer ? trim($i->offer->title().' '.$i->offer->year) : $i->car_title" :preview="'/work/money/links/'.$id.'/attach'">
                            <p class="px-1 text-sm text-accent-text" data-car-field-target="note" hidden></p>
                            @if ($car)<div class="list"><button class="row row-danger" name="clear" value="1">Отвязать</button></div>@endif
                            <div class="sheet-foot mt-auto"><x-ui.button block data-car-field-target="submit">Сохранить</x-ui.button></div>
                        </x-billing.car-field>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
    </x-ui.row-card>
    {{-- Действие в карточке меняет список «По ссылкам» (новая сумма, отмена, ТС): страница перечитывается морфом. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
