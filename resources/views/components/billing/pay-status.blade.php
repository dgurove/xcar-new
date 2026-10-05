{{-- Оплата счёта по ссылке — строками `.list`, одинаково сотруднику и менеджеру (05.10.2026, владелец: начальник не
     находит ссылку и не понимает, оплатили или нет). Ссылка заводится вместе со счётом (`EnsurePayLink`), поэтому здесь
     её не «делают», а берут:
     - строка «Оплата по ссылке» — кто платит и что сейчас (`PayLink::stateLine`: ждём, открывали, не прошла), сумма; нажатие — шторка: QR, текст для
       отправки, попытки плательщика, другая сумма, «Отменить ссылку»;
     - строка самого адреса — нажатие копирует его, справа «Отправить» (системный лист с готовым текстом).
     Ссылки нет (отменили руками) — «Новая ссылка на оплату» одним нажатием на остаток. Счёт, который ссылкой не платят
     (парковка, вознаграждение, обязательство, шлюз не подключён), — ничего. Адреса действий — по стороне: сотруднику
     `/work/money`, менеджеру `/account/money`; у гаража менеджеру свои (`create` — новая ссылка, `cancel` — адрес
     отмены без id): счёт его покупателю он видит только через машину.
     compact — узкое место (расчёт сделки, деньги машины в гараже, задача): ссылка — свойство счёта строкой под ним, без
     второй суммы (она строкой выше; другая — словами «на N ₽»): «Ссылка на оплату», под ней кто платит и что с ней,
     справа круглые «Скопировать» и «Отправить»; нажатие — та же шторка с QR (05.10.2026, владелец про карточку на три
     строки текста и две кнопки во всю ширину). --}}
@props(['invoice', 'staff' => false, 'create' => null, 'cancel' => null, 'compact' => false, 'inline' => false])
@php
    use App\Support\Money; use App\Billing\Acquiring\PayMethod;
    $i = $invoice;
    $link = $i->openLink();
    $payable = ! $link && \App\Billing\Acquiring\PayLink::eligible($i) && $i->remaining() - $i->claimed() > 0;
    $base = $staff ? '/work/money' : '/account/money';
    $create ??= $base.'/invoices/'.$i->id.'/links';
    $cancel ??= $base.'/links';
    if ($link) {
        $link->loadMissing('attempts');
        [$state, $tone] = $link->stateLine();
        // Менеджер платит сам — «платите вы», а не своё имя (06.10.2026).
        // Ссылка, заведённая вместе со счётом, — на имя плательщика счёта: если это он сам, тоже «вы».
        // Сам выбрал себя (`ChangePayLinkPayer`) — по `payer_user_id`, а не по имени: сотрудник, открывший ту же страницу,
        // «вы» не видит.
        $viewer = auth()->user();
        $self = ! $staff && $viewer && (
            ($link->payer_kind === \App\Billing\Acquiring\PayerKind::Self && in_array($viewer->id, [$link->created_by, $link->payer_user_id], true))
            || ($link->payer_kind === \App\Billing\Acquiring\PayerKind::Other && $viewer->party_id && $i->party_id === $viewer->party_id && in_array($link->payer_name, [null, '', $i->party->name], true)));
        $pays = $self ? 'платите вы' : 'платит '.($link->payer_name ?: $i->party->name);
        $offer = $i->deal?->offer ?? \App\Garage\Car::ofInvoice($i)?->offer;
        $text = 'Оплата по счёту '.$i->label().($offer ? ' за '.$offer->titleWithYear() : '').': '.Money::exact($link->amount).'. Картой, СБП или SberPay по ссылке:';
    }
@endphp
@if ($link)
    <div data-controller="sheet" class="contents">
        @if ($compact)
            @php $other = abs($link->amount - ($i->remaining() - $i->claimed())) >= 0.01; @endphp
            <div class="row pay-link" data-controller="copy" data-copy-text-value="{{ $link->url() }}" data-copy-title-value="Оплата по счёту {{ $i->label() }}">
                <input type="hidden" value="{{ $text }}" data-copy-target="message">
                <button type="button" class="min-w-0 flex-1 text-left" data-action="sheet#open">
                    <span class="block">Ссылка на оплату@if ($other) <span class="nums">на {{ Money::rub($link->amount) }}</span>@endif</span>
                    <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ $pays }}, {{ preg_replace('/^ждём оплату, /u', '', $state) }}</span>
                </button>
                <button type="button" class="btn btn-quiet btn-round shrink-0" data-action="copy#copy" aria-label="Скопировать ссылку"><x-ui.icon name="copy" class="size-5"/></button>
                <button type="button" class="btn btn-accent btn-round shrink-0" data-action="copy#share" data-copy-target="share" aria-label="Отправить ссылку"><x-ui.icon name="share" class="size-5"/></button>
            </div>
        @elseif ($inline)
            {{-- Расчёт сделки (06.10.2026, владелец: «ссылку сразу, а не в отдельном окне»): сумма и кто платит — строками
                 выше, здесь что с ссылкой и сам адрес с «Скопировать» и «Отправить». --}}
            @php $other = abs($link->amount - ($i->remaining() - $i->claimed())) >= 0.01; @endphp
            <button type="button" class="row w-full text-left" data-action="sheet#open">
                <x-ui.row-icon name="qr" :tone="$tone === 'danger' ? 'danger' : 'accent'" size="s"/>
                <span class="min-w-0 flex-1">
                    <span class="block">Ссылка на оплату@if ($other) <span class="nums">на {{ Money::rub($link->amount) }}</span>@endif</span>
                    <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ preg_replace('/^ждём оплату, /u', '', $state) }}</span>
                </span>
                <x-ui.chevron/>
            </button>
            {{-- Адрес целиком (нажатие копирует) и круглые «Скопировать» / «Отправить»: в узкой колонке расчёта кнопки
                 словами уезжали под адрес. --}}
            <div class="row pay-link" data-controller="copy" data-copy-text-value="{{ $link->url() }}" data-copy-title-value="Оплата по счёту {{ $i->label() }}">
                <input type="hidden" value="{{ $text }}" data-copy-target="message">
                <button type="button" class="min-w-0 flex-1 break-all text-left text-accent-text" data-action="copy#copy" title="Скопировать">{{ $link->shortUrl() }}</button>
                <button type="button" class="btn btn-quiet btn-round shrink-0" data-action="copy#copy" aria-label="Скопировать ссылку"><x-ui.icon name="copy" class="size-5"/></button>
                <button type="button" class="btn btn-accent btn-round shrink-0" data-action="copy#share" data-copy-target="share" aria-label="Отправить ссылку"><x-ui.icon name="share" class="size-5"/></button>
            </div>
        @else
        <button type="button" class="row money-line w-full text-left" data-action="sheet#open">
            <x-ui.row-icon name="qr" :tone="$tone === 'danger' ? 'danger' : 'urgent'" size="s"/>
            <span class="min-w-0 flex-1">
                <span class="block">Оплата по ссылке<span class="text-ink-muted">, {{ $pays }}</span></span>
                <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ $state }}</span>
            </span>
            <span class="nums shrink-0 text-urgent">{{ Money::rub($link->amount) }}</span>
        </button>
        <div class="row pay-url" data-controller="copy" data-copy-text-value="{{ $link->url() }}" data-copy-title-value="Оплата по счёту {{ $i->label() }}">
            <input type="hidden" value="{{ $text }}" data-copy-target="message">
            <button type="button" class="pay-url-text" data-action="copy#copy" title="Скопировать">{{ $link->shortUrl() }}</button>
            <button type="button" class="btn btn-s btn-quiet" data-action="copy#copy"><x-ui.icon name="copy" class="size-4"/>Скопировать</button>
            <button type="button" class="btn btn-s btn-accent" data-action="copy#share" data-copy-target="share"><x-ui.icon name="share" class="size-4"/>Отправить</button>
        </div>
        @endif
        <x-ui.sheet :id="'link-'.$link->id" title="Оплата по ссылке" :open="session('open-link') === $link->id">
            <div class="flex flex-col gap-4">
                <div class="money-hero">
                    <span class="nums text-[32px] font-semibold leading-tight">{{ Money::rub($link->amount) }}</span>
                    <span class="text-ink-muted">{{ $pays }}@if ($link->payer_email), чек на {{ $link->payer_email }}@endif</span>
                </div>
                <div class="mx-auto w-52 rounded-(--radius-l) bg-white p-3 text-black">{!! \App\Support\Qr::svg($link->url()) !!}</div>
                <x-ui.copy-link :url="$link->url()" :title="'Оплата по счёту '.$i->label()" :message="$text"/>
                @if ($link->attempts->isNotEmpty())
                    <section>
                        <div class="list-cap">Плательщик открывал оплату</div>
                        <div class="list">
                            @foreach ($link->attempts->reverse()->take(5) as $a)
                                @php [$aText, $aTone] = match (true) { $a->succeeded() => ['оплачено', 'open'], $a->isPending() => ['не завершил', 'urgent'], $a->cancel_reason === 'expired_on_confirmation' => ['бросил', 'muted'], default => [$a->cancelLabel() ?? 'отклонено', 'danger'] }; @endphp
                                <x-money.line :icon="PayMethod::icon($a->method)" :title="$a->created_at->translatedFormat('j M, H:i')" :sub="$a->method ? PayMethod::label($a->method) : null" :tone="$aTone">
                                    <x-slot:acts><x-ui.state :tone="$aTone">{{ $aText }}</x-ui.state></x-slot:acts>
                                </x-money.line>
                            @endforeach
                        </div>
                    </section>
                @endif
                <details class="list">
                    <summary class="row cursor-pointer"><span class="min-w-0 flex-1">Другая сумма</span><x-ui.chevron/></summary>
                    <form method="post" action="{{ $create }}" class="flex flex-col gap-3 p-4">
                        @csrf
                        <label class="flex items-baseline justify-center gap-2">
                            <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0"
                                value="{{ Money::nums($link->amount, fmod($link->amount, 1) ? 2 : 0) }}" data-controller="digits" data-action="input->digits#format">
                            <span class="text-2xl text-ink-muted">₽</span>
                        </label>
                        @error('amount')<div class="text-center text-sm text-danger">{{ $message }}</div>@enderror
                        <x-ui.button variant="secondary" block>Новая ссылка на эту сумму</x-ui.button>
                    </form>
                </details>
                <form method="post" action="{{ $cancel }}/{{ $link->id }}" data-turbo-confirm="Отменить ссылку? Оплатить по ней будет нельзя">
                    @csrf @method('delete')
                    <x-ui.button variant="ghost" block class="text-ink-muted">Отменить ссылку</x-ui.button>
                </form>
            </div>
        </x-ui.sheet>
    </div>
@elseif ($payable)
    <form method="post" action="{{ $create }}" class="contents">
        @csrf
        <button class="row w-full text-left">
            <x-ui.row-icon name="qr" tone="accent" size="s"/>
            <span class="min-w-0 flex-1">Новая ссылка на оплату</span>
            <span class="nums shrink-0 text-ink-muted">{{ Money::rub(\App\Billing\Acquiring\PayLink::defaultAmount($i)) }}</span>
        </button>
    </form>
@endif
