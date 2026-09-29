{{-- «Оплатить» у менеджера — одна шторка на кабинет и гараж: способ (ссылкой, по счёту, наличными) раскрывает свои поля.
     Ссылкой — кто платит (я, мой покупатель, другой человек с телефоном для чека) и сумма; по счёту — PDF с QR для
     приложения банка и «Я оплатил» (платёжка по желанию: поступление найдётся по выписке); наличными — сумма и дата.
     Поля в скрытых панелях reveal выключает; обязательность проверяет сервер, `required` на них заблокировал бы отправку. --}}
@props(['id' => 'pay', 'invoices', 'action', 'pdf', 'buyers' => collect(), 'open' => false, 'otherName' => null, 'otherPhone' => null])
@php
    use App\Support\Money;
    // Без договора эквайринга способа «Ссылкой» нет вовсе.
    $online = app(\App\Billing\Acquiring\Gateway::class)->configured();
    $first = $invoices->first();
    $left = fn ($i) => rtrim(rtrim(number_format(max(0, $i->remaining() - $i->claimed()), 2, '.', ''), '0'), '.');
    // Поле не зовётся `method`: оно перекрыло бы form.method, и Turbo с обработчиками отправки ломались бы.
    $way = old('way', $online ? 'link' : 'transfer');
    $payer = old('payer', 'self');
@endphp
<x-ui.sheet :id="$id" title="Оплатить" :open="$open">
    <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-4" data-controller="reveal">
        @csrf
        @if ($invoices->count() > 1)
            <x-ui.field name="invoice" label="Счёт" :value="old('invoice', $first->id)" :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->label().', к оплате '.Money::rub($i->remaining() - $i->claimed())])->all()"/>
        @else
            <input type="hidden" name="invoice" value="{{ $first->id }}">
        @endif
        <div class="flex flex-wrap gap-2">
            @foreach (array_filter(['link' => $online ? 'Ссылкой' : null, 'transfer' => 'По счёту', 'cash' => 'Наличными']) as $k => $label)
                <label class="choice"><input type="radio" name="way" value="{{ $k }}" @checked($way === $k) data-action="reveal#pick"><span>{{ $label }}</span></label>
            @endforeach
        </div>

        @if ($online)
        <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="link" @if ($way !== 'link') hidden @endif>
            <div class="flex flex-col gap-3" data-controller="reveal">
                <span class="field-label -mb-1">Кто платит</span>
                <div class="flex flex-wrap gap-2">
                    <label class="choice"><input type="radio" name="payer" value="self" @checked($payer === 'self') data-action="reveal#pick"><span>Я</span></label>
                    @if ($buyers->isNotEmpty())<label class="choice"><input type="radio" name="payer" value="buyer" @checked($payer === 'buyer') data-action="reveal#pick"><span>Мой покупатель</span></label>@endif
                    <label class="choice"><input type="radio" name="payer" value="other" @checked($payer === 'other') data-action="reveal#pick"><span>Другой человек</span></label>
                </div>
                @if ($buyers->isNotEmpty())
                    <div class="list max-h-64 overflow-y-auto" data-reveal-target="pane" data-reveal-key="buyer" @if ($payer !== 'buyer') hidden @endif>
                        @foreach ($buyers as $b)
                            <label class="row row-check !py-2">
                                <x-ui.avatar :user="$b" :size="32"/>
                                <span class="min-w-0 flex-1"><span class="block truncate">{{ $b->name }}</span>@if ($b->phone)<span class="row-sub nums">{{ $b->phone }}</span>@endif</span>
                                <span class="check"><input type="radio" name="payer_user_id" value="{{ $b->id }}" @checked((int) old('payer_user_id') === $b->id)></span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @foreach (['payer_user_id', 'payer_phone'] as $key)
                    @error($key)<div class="text-sm text-danger">{{ $message }}</div>@enderror
                @endforeach
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-reveal-target="pane" data-reveal-key="other" @if ($payer !== 'other') hidden @endif>
                    <x-ui.field name="name" id="pay-name" label="Имя" :value="$otherName"/>
                    <x-ui.field name="phone" id="pay-phone" label="Телефон" :value="$otherPhone"/>
                </div>
            </div>
            <x-ui.field name="amount" id="pay-amount-link" label="Сумма, ₽" :value="$invoices->count() === 1 ? $left($first) : null"/>
            <x-ui.button block>Получить ссылку</x-ui.button>
        </div>
        @endif

        <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="transfer" @if ($way !== 'transfer') hidden @endif>
            @if ($pdf && $invoices->count() === 1)
                <div class="list">
                    <a href="{{ str_replace('{id}', $first->id, $pdf) }}" class="row justify-between" data-turbo="false" target="_blank"><span>Счёт {{ $first->label() }} с QR для банка</span><x-ui.icon name="file" class="size-5 text-ink-dim"/></a>
                </div>
            @endif
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="amount" id="pay-amount-transfer" label="Сумма, ₽" :value="$invoices->count() === 1 ? $left($first) : null"/>
                <x-ui.field name="paid_at" id="pay-date-transfer" label="Дата оплаты" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                <x-ui.field name="ref" label="№ платёжки"/>
                <x-ui.field name="slip" label="Платёжка" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic,image/*"/>
            </div>
            <x-ui.button block>Я оплатил</x-ui.button>
        </div>

        <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="cash" @if ($way !== 'cash') hidden @endif>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="amount" id="pay-amount-cash" label="Сумма, ₽" :value="$invoices->count() === 1 ? $left($first) : null"/>
                <x-ui.field name="paid_at" id="pay-date-cash" label="Когда отдали" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
            </div>
            <x-ui.button block>Отдал наличными</x-ui.button>
        </div>
    </form>
</x-ui.sheet>
