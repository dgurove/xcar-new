{{-- «Оплатить» у менеджера, как перевод в банковском приложении: способ сегментом (ссылкой, по счёту, наличными), сумма
     крупно по центру, у ссылки — кто платит строками с аватарами (я, мои покупатели, другой человек). Почта для чека —
     только у того, у кого её нет в профиле: чек ЮKassa приходит только на почту. По счёту — PDF с QR строкой документа,
     дата, номер и платёжка; наличными — дата. Кнопка одна внизу и называет исход (reveal подменяет подпись).
     Поля в скрытых панелях reveal выключает; обязательность проверяет сервер, `required` на них заблокировал бы отправку. --}}
@props(['id' => 'pay', 'invoices', 'action', 'pdf', 'buyers' => collect(), 'open' => false, 'otherName' => null, 'otherPhone' => null])
@php
    use App\Support\Money; use App\Billing\Acquiring\PayLink;
    // Без договора эквайринга способа «Ссылкой» нет вовсе.
    $online = app(\App\Billing\Acquiring\Gateway::class)->configured();
    $first = $invoices->first();
    $one = $invoices->count() === 1;
    $left = $one ? max(0, round($first->remaining() - $first->claimed(), 2)) : null;
    $max = (float) config('xcar.yookassa.max_amount');
    $fmt = fn ($v) => Money::nums($v, fmod($v, 1) ? 2 : 0);
    // Поле не зовётся `method`: оно перекрыло бы form.method, и Turbo с обработчиками отправки ломались бы.
    $way = old('way', $online ? 'link' : 'transfer');
    $ways = array_filter(['link' => $online ? ['Ссылкой', 'Получить ссылку'] : null, 'transfer' => ['По счёту', 'Я оплатил'], 'cash' => ['Наличными', 'Отдал наличными']]);
    $payer = (string) old('payer', 'self');
    $picked = fn ($b) => $payer === (string) $b->id || ($payer === 'buyer' && (int) old('payer_user_id') === $b->id);
    $me = auth()->user();
    $submit = $id.'-submit';
@endphp
<x-ui.sheet :id="$id" title="Оплатить" :open="$open">
    <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-5" data-controller="reveal pay-amount" data-reveal-label-selector-value="#{{ $submit }}">
        @csrf
        <div class="segment">
            @foreach ($ways as $k => [$label, $verb])
                <label><input type="radio" name="way" value="{{ $k }}" @checked($way === $k) data-action="reveal#pick" data-reveal-label="{{ $verb }}"><span>{{ $label }}</span></label>
            @endforeach
        </div>

        @if ($one)
            <input type="hidden" name="invoice" value="{{ $first->id }}">
        @else
            <x-ui.field name="invoice" label="Счёт" :value="old('invoice', $first->id)" :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->label().', к оплате '.Money::rub($i->remaining() - $i->claimed())])->all()"/>
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
                <div data-controller="reveal">
                    <div class="list-cap">Кто платит</div>
                    <div class="list max-h-72 overflow-y-auto">
                        <label class="row row-check">
                            <x-ui.avatar :user="$me" :size="36"/>
                            <span class="min-w-0 flex-1"><span class="block truncate">Я</span>@if ($me->email)<span class="row-sub">{{ $me->email }}</span>@endif</span>
                            <span class="check"><input type="radio" name="payer" value="self" @checked($payer === 'self') data-action="reveal#pick"></span>
                        </label>
                        @foreach ($buyers as $b)
                            <label class="row row-check">
                                <x-ui.avatar :user="$b" :size="36"/>
                                <span class="min-w-0 flex-1"><span class="block truncate">{{ $b->name }}</span>@if ($b->email || $b->phone)<span class="row-sub">{{ $b->email ?: $b->phone }}</span>@endif</span>
                                <span class="check"><input type="radio" name="payer" value="{{ $b->id }}" @checked($picked($b)) data-action="reveal#pick"></span>
                            </label>
                        @endforeach
                        <label class="row row-check">
                            <x-ui.row-icon name="plus" size="s"/>
                            <span class="min-w-0 flex-1">Другой человек</span>
                            <span class="check"><input type="radio" name="payer" value="other" @checked($payer === 'other') data-action="reveal#pick"></span>
                        </label>
                    </div>
                    @error('payer_user_id')<div class="mt-2 text-sm text-danger">{{ $message }}</div>@enderror
                    @unless ($me->email)
                        <div class="mt-3" data-reveal-target="pane" data-reveal-key="self" @if ($payer !== 'self') hidden @endif><x-ui.field name="email" id="{{ $id }}-email-self" label="Почта для чека" type="email"/></div>
                    @endunless
                    @foreach ($buyers->reject(fn ($b) => $b->email) as $b)
                        <div class="mt-3" data-reveal-target="pane" data-reveal-key="{{ $b->id }}" @unless ($picked($b)) hidden @endunless><x-ui.field name="email" id="{{ $id }}-email-{{ $b->id }}" label="Почта для чека" type="email"/></div>
                    @endforeach
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" data-reveal-target="pane" data-reveal-key="other" @if ($payer !== 'other') hidden @endif>
                        <x-ui.field name="name" id="{{ $id }}-name" label="Имя" :value="$otherName" span="sm:col-span-2"/>
                        <x-ui.field name="email" id="{{ $id }}-email-other" label="Почта для чека" type="email"/>
                        <x-ui.field name="phone" id="{{ $id }}-phone" label="Телефон" :value="$otherPhone"/>
                    </div>
                </div>
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
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="paid_at" id="{{ $id }}-date-transfer" label="Дата оплаты" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="ref" id="{{ $id }}-ref" label="№ платёжки"/>
                <x-ui.file-field name="slip" label="Платёжка" accept=".pdf,.jpg,.jpeg,.png,.heic,image/*" span="col-span-2"/>
            </div>
        </div>

        <div data-reveal-target="pane" data-reveal-key="cash" @if ($way !== 'cash') hidden @endif>
            <x-ui.field name="paid_at" id="{{ $id }}-date-cash" label="Когда отдали" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
        </div>

        <x-ui.button block id="{{ $submit }}">{{ $ways[$way][1] ?? 'Оплатить' }}</x-ui.button>
    </form>
</x-ui.sheet>
