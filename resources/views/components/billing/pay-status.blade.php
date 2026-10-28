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
@props(['invoice', 'staff' => false, 'create' => null, 'cancel' => null, 'compact' => false, 'link' => null])
@php
    use App\Support\Money; use App\Billing\Acquiring\PayMethod;
    $i = $invoice;
    // Карточка ссылки в «Оплатах» передаёт её саму: оплаченная и отменённая тоже рисуются, без «Новой ссылки».
    $link ??= $i->openLink();
    $live = $link?->isOpen();
    $payable = ! $link && \App\Billing\Acquiring\PayLink::eligible($i) && $i->remaining() - $i->claimed() > 0;
    $base = $staff ? '/work/money' : '/account/money';
    $create ??= $base.'/invoices/'.$i->id.'/links';
    $cancel ??= $base.'/links';
    if ($link) {
        $link->loadMissing('attempts');
        [$state, $tone] = $link->stateLine();
        // Менеджер платит сам — «платите вы», а не своё имя (06.10.2026).
        // Ссылка, заведённая вместе со счётом, — на имя плательщика счёта: если это он сам, тоже «вы».
        // Сам за себя — по тому, кто завёл ссылку, а не по имени: сотрудник, открывший ту же страницу, «вы» не видит.
        $viewer = auth()->user();
        $self = ! $staff && $viewer && (
            ($link->payer_kind === \App\Billing\Acquiring\PayerKind::Self && $viewer->id === $link->created_by)
            || ($link->payer_kind === \App\Billing\Acquiring\PayerKind::Other && $viewer->party_id && $i->party_id === $viewer->party_id && in_array($link->payer_name, [null, '', $i->party->name], true)));
        $pays = $self ? 'платите вы' : 'платит '.$link->payerName();
        $text = $link->message();
    }
@endphp
@if ($link)
    <div data-controller="sheet" class="contents">
        @if ($compact)
            @php $other = abs($link->amount - ($i->remaining() - $i->claimed())) >= 0.01; @endphp
            <x-ui.share-link variant="row" :url="$link->url()" :title="'Оплата по счёту '.$i->label()" :text="$text" class="pay-link">
                <x-slot:lead>
                    <button type="button" class="min-w-[9rem] flex-1 text-left" data-action="sheet#open">
                        <span class="block">Ссылка на оплату@if ($other) <span class="nums">на {{ Money::rub($link->amount) }}</span>@endif</span>
                        <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ $pays }}, {{ preg_replace('/^ждём оплату, /u', '', $state) }}</span>
                    </button>
                </x-slot:lead>
            </x-ui.share-link>
        @else
        {{-- Сумма — только если ссылка не на остаток счёта: та же сумма стоит строкой выше, у счёта. --}}
        @php $other = abs($link->amount - ($i->remaining() - $i->claimed())) >= 0.01; @endphp
        {{-- Одна строка: что с оплатой (нажатие — шторка ссылки) и, пока ссылка жива, «Скопировать» / «Отправить» справа
             (`x-ui.share-link`, 28.10.2026: была второй строкой «Ссылка на оплату» под той же). --}}
        @if ($live)
            <x-ui.share-link variant="row" :url="$link->url()" :title="'Оплата по счёту '.$i->label()" :text="$text" class="money-line">
                <x-slot:lead>
                    <button type="button" class="flex min-w-[9rem] flex-1 items-center gap-3 text-left" data-action="sheet#open">
                        <x-ui.row-icon name="qr" :tone="$tone === 'danger' ? 'danger' : 'urgent'" size="s"/>
                        <span class="min-w-0 flex-1">
                            <span class="block">Оплата по ссылке<span class="text-ink-muted">, {{ $pays }}</span>@if ($other) <span class="nums text-urgent">{{ Money::rub($link->amount) }}</span>@endif</span>
                            <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ $state }}</span>
                        </span>
                    </button>
                </x-slot:lead>
            </x-ui.share-link>
        @else
            <button type="button" class="row money-line w-full text-left" data-action="sheet#open">
                <x-ui.row-icon name="qr" :tone="$tone === 'danger' ? 'danger' : 'urgent'" size="s"/>
                <span class="min-w-0 flex-1">
                    <span class="block">Оплата по ссылке<span class="text-ink-muted">, {{ $pays }}</span></span>
                    <span class="row-sub !whitespace-normal {{ match ($tone) { 'danger' => '!text-danger', 'urgent' => '!text-urgent', default => '' } }}">{{ $state }}</span>
                </span>
                @if ($other)<span class="nums shrink-0 text-urgent">{{ Money::rub($link->amount) }}</span>@endif
            </button>
        @endif
        @endif
        <x-ui.sheet :id="'link-'.$link->id" title="Оплата по ссылке" :open="session('open-link') === $link->id">
            <div class="flex flex-col gap-4">
                <div class="money-hero">
                    <span class="nums text-[32px] font-semibold leading-tight">{{ Money::rub($link->amount) }}</span>
                    <span class="text-ink-muted">{{ $pays }}@if ($link->payer_email), чек на {{ $link->payer_email }}@endif</span>
                </div>
                <x-billing.pay-qr :link="$link" :main="false"/>
                <x-ui.share-link :url="$link->url()" :title="'Оплата по счёту '.$i->label()" :text="$text" editable :rows="5"/>
                <x-billing.link-attempts :link="$link"/>
                @if ($live)
                <details class="list">
                    <summary class="row cursor-pointer"><span class="min-w-0 flex-1">Другая сумма</span><x-ui.chevron/></summary>
                    {{-- Ручная перевыставляется новым счётом (`ReissueServiceInvoice`) — карточка открывается уже на новой ссылке. --}}
                    <form method="post" action="{{ $create }}" class="flex flex-col gap-3 p-4" @if ($staff && $i->isService()) data-turbo-frame="_top" @endif>
                        @csrf
                        <label class="flex items-baseline justify-center gap-2">
                            <x-billing.amount :value="Money::nums($link->amount, fmod($link->amount, 1) ? 2 : 0)"/>
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
                @endif
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
