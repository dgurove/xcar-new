{{-- «Оплатить» у менеджера, как перевод в банковском приложении: способ сегментом (ссылкой, по счёту, наличными), сумма
     крупно по центру, у ссылки — кто платит строками с аватарами (я, мои покупатели, новый покупатель — заводится в его
     «Покупателях»). Почта для чека —
     только у того, у кого её нет в профиле: чек ЮKassa приходит только на почту. По счёту — PDF с QR строкой документа,
     дата, номер и платёжка; наличными — дата. Кнопка — у каждого способа своя и называет исход; у ссылки её нет: выбор
     плательщика сам заводит новую ссылку, и она тут же, с «Скопировать» и «Отправить».
     Поля в скрытых панелях reveal выключает; обязательность проверяет сервер, `required` на них заблокировал бы отправку.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['id' => 'pay', 'invoices', 'action', 'pdf', 'buyers' => collect(), 'open' => false, 'otherName' => null, 'offline' => false])
@php
    use App\Support\Money; use App\Billing\Acquiring\PayLink;
    $first = $invoices->first();
    $one = $invoices->count() === 1;
    // Без договора эквайринга способа «По ссылке» нет вовсе. Ссылка у счёта уже есть (заводится вместе с ним на менеджера) —
    // «По ссылке» всё равно здесь: выбрать, кто платит (05.10.2026, владелец), новая заменит прежнюю.
    // У счёта ПРАЙМ по сделке ссылки нет вовсе (Совкомбанк: «ссылка не используется», `PayLink::eligible`).
    // offline — ссылка уже стоит рядом (расчёт сделки): здесь только «по счёту» и «наличными».
    $online = ! $offline && app(\App\Billing\Acquiring\Gateway::class)->configured() && $invoices->every(fn ($i) => PayLink::eligible($i));
    $current = $one ? $first->openLink() : null;
    $left = $one ? max(0, round($first->remaining() - $first->claimed(), 2)) : null;
    $max = (float) config('xcar.yookassa.max_amount');
    $fmt = fn ($v) => Money::nums($v, fmod($v, 1) ? 2 : 0);
    // Поле не зовётся `method`: оно перекрыло бы form.method, и Turbo с обработчиками отправки ломались бы.
    $way = old('way', $online ? 'link' : 'transfer');
    $ways = array_filter(['link' => $online ? ['По ссылке', 'Получить ссылку'] : null, 'transfer' => ['По счёту', 'Я оплатил'], 'cash' => ['Наличными', 'Отдал наличными']]);
    $payer = (string) old('payer', $current?->payer_kind === \App\Billing\Acquiring\PayerKind::Buyer && $current->payer_user_id ? (string) $current->payer_user_id : 'self');
    $me = auth()->user();
@endphp
<x-ui.sheet :id="$id" title="Оплатить" :open="$open">
    <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-4" data-controller="reveal pay-amount autosubmit">
        @csrf
        <div class="segment">
            @foreach ($ways as $k => [$label, $verb])
                <label><input type="radio" name="way" value="{{ $k }}" @checked($way === $k) data-action="reveal#pick"><span>{{ $label }}</span></label>
            @endforeach
        </div>

        @if ($one)
            <input type="hidden" name="invoice" value="{{ $first->id }}">
        @else
            <div class="fields">
                <x-ui.field name="invoice" label="Счёт" :value="old('invoice', $first->id)" :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->label().', к оплате '.Money::rub($i->remaining() - $i->claimed())])->all()"/>
            </div>
        @endif

        <div class="flex flex-col items-center gap-2">
            <label class="flex w-full items-baseline justify-center gap-2">
                <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Сумма, ₽" placeholder="0"
                    value="{{ old('amount', $one ? $fmt($online && $way === 'link' ? PayLink::defaultAmount($first) : $left) : '') }}" data-controller="digits" data-action="input->digits#format" data-pay-amount-target="input">
                <span class="text-2xl text-ink-muted">₽</span>
            </label>
            @if ($one && $max > 0 && $left > $max)
                <div class="flex gap-2">
                    <button type="button" class="chip nums" data-action="pay-amount#set" data-pay-amount-value-param="{{ $fmt($max) }}">{{ $fmt($max) }}</button>
                    <button type="button" class="chip" data-action="pay-amount#set" data-pay-amount-value-param="{{ $fmt($left) }}">весь остаток</button>
                </div>
            @endif
            @error('amount')<div class="text-sm text-danger">{{ $message }}</div>@enderror
        </div>

        @if ($online)
            <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="link" @if ($way !== 'link') hidden @endif>
                {{-- Кто платит — выбор сразу заводит новую ссылку на него, прежняя гаснет (`CreatePayLink`, 06.10.2026,
                     владелец); новому покупателю — после ФИО, кнопкой. Ниже — сама ссылка. --}}
                <x-billing.payer-pick :id="$id" :buyers="$buyers" :payer="$payer" :other-name="$otherName" autosubmit/>
                @if ($current)
                    @php [$state] = $current->stateLine(); @endphp
                    <div>
                        <div class="list-cap">Ссылка на оплату, {{ preg_replace('/^ждём оплату, /u', '', $state) }}</div>
                        <x-ui.copy-link :url="$current->url()" :title="'Оплата по счёту '.$first->label()"/>
                    </div>
                @else
                    <div class="sheet-foot"><x-ui.button block>Получить ссылку</x-ui.button></div>
                @endif
            </div>
        @endif

        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="transfer" @if ($way !== 'transfer') hidden @endif>
            @if ($pdf && $one)
                <div class="list">
                    <x-ui.doc :doc="['url' => str_replace('{id}', $first->id, $pdf), 'type' => 'pdf', 'name' => 'schet-'.$first->number.'.pdf', 'label' => 'Счёт '.$first->label()]" class="row">
                        <x-ui.row-icon name="qr" size="s"/>
                        <span class="min-w-0 flex-1">Счёт {{ $first->label() }} с QR для банка</span>
                        <x-ui.chevron/>
                    </x-ui.doc>
                </div>
            @endif
            <div class="fields">
                <x-ui.field name="paid_at" id="{{ $id }}-date-transfer" label="Дата оплаты" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="ref" id="{{ $id }}-ref" label="№ платёжки"/>
                <x-ui.file-field name="slip" label="Платёжка" accept=".pdf,.jpg,.jpeg,.png,.heic,image/*"/>
            </div>
            <div class="sheet-foot"><x-ui.button block>Я оплатил</x-ui.button></div>
        </div>

        <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="cash" @if ($way !== 'cash') hidden @endif>
            <div class="fields">
                <x-ui.field name="paid_at" id="{{ $id }}-date-cash" label="Когда отдали" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
            </div>
            <div class="sheet-foot"><x-ui.button block>Отдал наличными</x-ui.button></div>
        </div>
    </form>
</x-ui.sheet>
