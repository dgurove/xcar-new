{{-- Карточка ссылки на оплату — строка «По ссылкам», как запрос на оплату в банковском приложении (09.10.2026, владелец:
     «слишком много лишней фигни»): кто платит, сумма и статус, сразу «Отправить» и «Скопировать» (текст «ФИО, ТС, сумма» и
     адрес, `PayLink::message`); ниже строками ТС, кто взял (или сделка, гараж), счёт, почта для чека; попытки — если
     открывали. Редкое — в «···» полосы: QR-код, другая сумма, ТС у ручной, отменить, вернуть. Оплаченная и отменённая —
     без кнопок. --}}
@php
    use App\Support\Money; use App\Http\Admin\MoneyLinks as L; use App\Billing\Acquiring\PayLinkState; use App\Billing\Acquiring\PayMethod; use App\Billing\InvoiceState;
    $i = $invoice;
    $id = $link->id;
    $live = $link->isOpen();
    $manual = $link->origin() === 'manual';
    $offer = $i->carOffer();
    [$car, $number] = L::car($link);
    [$state, $tone] = L::state($link);
    // Оплачено — каким способом.
    $paidBy = $link->state === PayLinkState::Paid ? $link->attempts->firstWhere('payment_id', $link->payment_id)?->method : null;
    if ($paidBy) { $state .= ', '.PayMethod::label($paidBy); }
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
        $applied > 0 => 'в зачёт '.Money::rub($applied).', сверх сделки '.Money::rub($i->paid - $applied),
        default => 'сверх сделки '.Money::rub($i->paid),
    };
    $sheet = fn (string $name) => 'data-controller="emit" data-action="menu#close emit#send" data-emit-event-param="link-'.$id.'-'.$name.':open"';
@endphp
<x-ui.detail>
    <x-ui.row-card :title="$link->payerName()" :photo="false" :action="null">
        <x-slot:tools>
            <div class="contents" data-controller="menu">
                <button type="button" class="bar-btn" data-action="menu#toggle" aria-haspopup="menu" aria-controls="link-more-{{ $id }}" aria-label="Ещё"><x-ui.icon name="more" class="size-[18px]"/></button>
                <div id="link-more-{{ $id }}" class="menu" popover data-menu-target="list" role="menu">
                    @if ($live)<button type="button" class="menu-item w-full" role="menuitem" {!! $sheet('qr') !!}>QR-код</button>@endif
                    @if ($other)<button type="button" class="menu-item w-full" role="menuitem" {!! $sheet('amount') !!}>Другая сумма</button>@endif
                    @if ($editCar)<button type="button" class="menu-item w-full" role="menuitem" {!! $sheet('car') !!}>{{ $car ? 'Другое ТС' : 'Указать ТС' }}</button>@endif
                    <a href="/work/money/invoices/{{ $i->id }}" class="menu-item w-full" role="menuitem" data-turbo-frame="_top">Счёт {{ $i->label() }}</a>
                    @if ($refund)
                        <form method="post" action="/work/money/acquiring/{{ $refund->id }}/refund" class="contents" data-turbo-confirm="Вернуть {{ Money::exact($refund->refundable()) }} плательщику? Оплата по счёту отменится">@csrf<button class="menu-item w-full" role="menuitem">Вернуть {{ Money::rub($refund->refundable()) }}</button></form>
                    @endif
                    @if ($live)
                        <form method="post" action="/work/money/links/{{ $id }}" class="contents" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">@csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Отменить ссылку</button></form>
                    @endif
                </div>
            </div>
        </x-slot:tools>
        <x-slot:marks>
            <span class="nums text-lg font-semibold">{{ Money::rub($link->amount) }}</span>
            <x-ui.state :tone="match (true) { $link->state === PayLinkState::Paid => 'open', $link->state === PayLinkState::Canceled => 'closed', default => $link->stateLine()[1] ?? 'plain' }">{{ $state }}</x-ui.state>
        </x-slot:marks>

        @if ($live)
            <div class="mt-4 flex gap-2" data-controller="copy" data-copy-text-value="{{ $link->url() }}" data-copy-title-value="Оплата по ссылке">
                <textarea hidden data-copy-target="message">{{ $link->message() }}</textarea>
                <button type="button" class="btn btn-s btn-accent flex-1" data-action="copy#share" data-copy-target="share"><x-ui.icon name="share" class="size-4"/>Отправить</button>
                <button type="button" class="btn btn-s btn-quiet flex-1" data-action="copy#copy" data-copy-fallback><x-ui.icon name="copy" class="size-4"/>Скопировать</button>
            </div>
        @endif

        <div class="list mt-4">
            @if ($car)
                <a @if ($offer) href="/offers/{{ $offer->number }}" data-turbo-frame="_top" @endif class="row">
                    <span class="min-w-0 flex-1 text-ink-muted">ТС</span>
                    <span class="min-w-0 truncate text-right">{{ $car }}@if ($number)<span class="nums text-ink-muted">, № {{ $number }}</span>@endif</span>
                    @if ($offer)<x-ui.chevron/>@endif
                </a>
            @elseif ($editCar)
                <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="link-{{ $id }}-car:open">
                    <span class="min-w-0 flex-1 text-ink-muted">ТС</span><span class="text-accent-text">Указать</span><x-ui.chevron/>
                </button>
            @endif
            @if ($manual)
                <div class="row">
                    <span class="min-w-0 flex-1 text-ink-muted">Взял</span>
                    <span class="nums text-right">{{ $by?->shortName() ?? 'вручную' }}, {{ $link->created_at->translatedFormat('j M, H:i') }}</span>
                </div>
                @if ($i->creditDeal && $dealUrl)
                    <a href="{{ $dealUrl }}" class="row" data-turbo-frame="_top">
                        <span class="min-w-0 flex-1 text-ink-muted">Сделка</span>
                        <span class="min-w-0 text-right">{{ $i->creditDeal->buyer?->shortName() }}@if ($creditWord)<span class="block text-sm text-ink-muted">{{ $creditWord }}</span>@endif</span>
                        <x-ui.chevron/>
                    </a>
                @endif
            @else
                <a @if ($dealUrl) href="{{ $dealUrl }}" @elseif ($offer) href="/work/garage?preset=all&peek={{ $offer->number }}" @endif class="row" data-turbo-frame="_top">
                    <span class="min-w-0 flex-1 text-ink-muted">{{ $link->origin() === 'deal' ? 'Сделка' : 'Гараж' }}</span>
                    <span class="nums text-right">@if ($by){{ $by->shortName() }}, @endif{{ $link->created_at->translatedFormat('j M, H:i') }}</span>
                    <x-ui.chevron/>
                </a>
            @endif
            @if ($link->payer_email)
                <div class="row"><span class="min-w-0 flex-1 text-ink-muted">Чек</span><span class="min-w-0 truncate text-right">{{ $link->payer_email }}</span></div>
            @endif
        </div>

        @if ($link->attempts->isNotEmpty())
            <div class="mt-4"><x-billing.link-attempts :link="$link" :limit="20"/></div>
        @endif

        @if ($live)
            <div data-controller="sheet" data-action="link-{{ $id }}-qr:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'link-qr-'.$id" title="QR-код">
                    <div class="flex flex-col items-center gap-4">
                        <div class="w-56 rounded-(--radius-l) bg-white p-3 text-black">{!! \App\Support\Qr::svg($link->url()) !!}</div>
                        <div class="nums select-all break-all text-center text-ink-muted">{{ $link->shortUrl() }}</div>
                    </div>
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
            <div data-controller="sheet" data-action="link-{{ $id }}-car:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'link-car-'.$id" title="ТС">
                    {{-- Машина и есть сделка (владелец 09.10.2026): ищут по номеру, ДЛ или VIN; ТС со сделкой — оплата идёт в
                         её зачёт. Что будет — строкой под полем (`DealCredit::preview`), до «Сохранить». --}}
                    <form method="post" action="/work/money/links/{{ $id }}/car" class="flex flex-col gap-4"
                          data-controller="fetch-into" data-fetch-into-url-value="/work/money/links/{{ $id }}/attach" data-fetch-into-fields-value='["offer_id","car_title"]'
                          data-action="change->fetch-into#changed input->fetch-into#changed">
                        @csrf @method('put')
                        <div class="fields">
                            <x-ui.combobox name="offer_id" text-name="car_title" label="ТС" url="/work/money/cars" placeholder="Номер, ДЛ, VIN или марка"
                                :value="$i->offer_id" :text="$i->offer ? trim($i->offer->title().' '.$i->offer->year) : $i->car_title"/>
                        </div>
                        <div data-fetch-into-target="box"></div>
                        <div class="sheet-foot flex flex-col gap-1">
                            <x-ui.button block>Сохранить</x-ui.button>
                            @if ($car)<x-ui.button variant="ghost" block class="text-ink-muted" name="clear" value="1">Отвязать</x-ui.button>@endif
                        </div>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
    </x-ui.row-card>
    {{-- Действие в карточке меняет список «По ссылкам» (новая сумма, отмена, ТС): страница перечитывается морфом. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
