{{-- Расчёт по машине. Сотруднику — весь расклад, поле вознаграждения и счёт; менеджеру —
     за сколько продана, его вознаграждение (после счёта) и сколько отдать нам. --}}
@php
    use App\Support\Money;
    $s = \App\Garage\Settlement::of($car);
    $invoice = $car->invoice;
    $n = $car->offer->number;
@endphp
<div class="box">
    <h2>Расчёт</h2>
    <dl class="mt-4 grid grid-cols-[1fr_auto] items-baseline gap-x-4 gap-y-2">
        <dt class="text-sm text-ink-dim">Продана за</dt><dd class="nums text-right font-medium">{{ Money::rub($car->sold_price) }}</dd>
        @if ($staff)
            <dt class="text-sm text-ink-dim">Отдали за</dt><dd class="nums text-right">{{ Money::rub($car->cost) }}</dd>
            <dt class="text-sm text-ink-dim">Расходы менеджера</dt><dd class="nums text-right">{{ Money::exact($s['manager_costs']) }}</dd>
            @if ($s['our_costs'] > 0)<dt class="text-sm text-ink-dim">Расходы XCar</dt><dd class="nums text-right">{{ Money::exact($s['our_costs']) }}</dd>@endif
            <dt class="text-sm text-ink-dim">Прибыль</dt><dd class="nums text-right font-semibold {{ $s['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::exact($s['profit']) }}</dd>
        @elseif ($invoice)
            <dt class="text-sm text-ink-dim">Ваше вознаграждение</dt><dd class="nums text-right font-medium">{{ Money::rub($s['fee']) }}</dd>
        @endif
    </dl>

    @if ($car->buyer_name)
        <p class="mt-3 text-sm text-ink-muted">Покупатель {{ $car->buyer_name }}@if ($car->buyer_phone), <a href="tel:{{ $car->buyer_phone }}" class="text-accent-text">{{ $car->buyer_phone }}</a>@endif</p>
    @endif

    {{-- Вознаграждение назначаем мы и только до счёта: после него сумма уже в документе. --}}
    @if ($staff && $car->manager && ! $invoice)
        <form method="post" action="/cars/{{ $n }}/sold" class="mt-5 flex flex-col gap-3" data-controller="commission" data-commission-margin-value="{{ (int) $s['profit'] }}">
            @csrf
            <input type="hidden" name="sold_price" value="{{ $car->sold_price }}">
            <input type="hidden" name="sold_at" value="{{ $car->sold_at->toDateString() }}">
            <input type="hidden" name="buyer_name" value="{{ $car->buyer_name }}">
            <input type="hidden" name="buyer_phone" value="{{ $car->buyer_phone }}">
            <div class="field">
                <label for="fee" class="field-label">Вознаграждение менеджеру, ₽</label>
                <input type="hidden" name="commission" data-commission-target="amount" value="{{ old('commission', $car->commission) }}">
                <input id="fee" type="text" inputmode="numeric" class="field-input nums text-lg" data-commission-target="display" data-action="input->commission#input" value="{{ $car->commission ? Money::nums($car->commission) : '' }}" autocomplete="off" placeholder="0">
                @error('commission')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <dl class="grid grid-cols-[1fr_auto] items-baseline gap-x-4">
                <dt class="text-sm text-ink-dim">Нам остаётся</dt><dd class="nums text-right text-lg font-semibold" data-commission-target="ours">{{ Money::exact($s['ours']) }}</dd>
            </dl>
            <x-ui.button type="submit" variant="secondary" block>Сохранить вознаграждение</x-ui.button>
        </form>
    @endif

    <div class="list mt-5">
        @if ($s['due'] !== null)
            <div class="row">
                <span class="flex-1 font-medium">{{ $invoice && $invoice->remaining() <= 0 ? 'Рассчитались' : ($staff ? 'Менеджер отдаёт нам' : 'Отдать нам') }}</span>
                <span class="nums shrink-0 text-lg font-semibold">{{ Money::exact($s['due']) }}</span>
            </div>
        @endif
        @if ($invoice)
            <div class="row">
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">Счёт {{ $invoice->label() }}</span>
                    <span class="row-sub">{{ $invoice->state->label() }}@if ($invoice->remaining() > 0), до {{ $invoice->due_at->translatedFormat('j M') }}@endif</span>
                </span>
                <a href="/cars/{{ $n }}/invoice/pdf" class="btn btn-s btn-quiet shrink-0" data-turbo="false" target="_blank"><x-ui.icon name="file" class="size-4"/> PDF</a>
            </div>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @if ($staff && ! $invoice)
            <div data-controller="sheet" class="contents">
                <x-ui.button type="button" variant="primary" data-action="sheet#open">{{ $car->manager ? 'Выставить счёт' : 'Закрыть расчёт' }}</x-ui.button>
                <x-ui.sheet id="settle" title="{{ $car->manager ? 'Счёт менеджеру' : 'Закрыть расчёт' }}">
                    <form method="post" action="/cars/{{ $n }}/settle" class="flex flex-col gap-4">
                        @csrf
                        @if ($car->manager)
                            <p class="text-ink-muted">Счёт на {{ Money::exact($s['due'] + $s['fee']) }} двумя строками: транспортное средство и вознаграждение менеджеру. Вознаграждение он удерживает сам, к оплате останется {{ Money::exact($s['due']) }}</p>
                            <x-ui.check name="vat" :checked="$car->offer->prices_include_vat">С НДС</x-ui.check>
                        @else
                            <p class="text-ink-muted">Машина была наша, счёт выставлять некому</p>
                        @endif
                        <x-ui.button type="submit" variant="primary" block>{{ $car->manager ? 'Выставить' : 'Закрыть' }}</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
            <form method="post" action="/cars/{{ $n }}/sold" data-turbo-confirm="Снять итог продажи?">@csrf @method('delete')<x-ui.button type="submit" variant="ghost">Не продана</x-ui.button></form>
        @endif

        @if ($staff && $invoice && $invoice->remaining() > 0)
            <div data-controller="sheet" class="contents">
                <x-ui.button type="button" variant="primary" data-action="sheet#open">Поступило</x-ui.button>
                <x-ui.sheet id="paid" title="Деньги пришли" :open="$errors->has('amount')">
                    <form method="post" action="/cars/{{ $n }}/payments" class="flex flex-col gap-4">
                        @csrf
                        <x-ui.field name="amount" label="Сколько, ₽" :value="rtrim(rtrim(number_format($invoice->remaining(), 2, ',', ''), '0'), ',')" inputmode="decimal"/>
                        <x-ui.field name="paid_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                        <x-ui.button type="submit" variant="primary" block>Записать</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
            <form method="post" action="/cars/{{ $n }}/invoice" data-turbo-confirm="Аннулировать счёт?">@csrf @method('delete')<x-ui.button type="submit" variant="ghost">Аннулировать счёт</x-ui.button></form>
        @endif

        @if (! $staff && $invoice && $invoice->remaining() > 0)
            <div data-controller="sheet" class="contents">
                <x-ui.button type="button" variant="primary" data-action="sheet#open">Сообщить об оплате</x-ui.button>
                <x-ui.sheet id="claim" title="Сообщить об оплате" :open="$errors->any()">
                    <form method="post" action="/cars/{{ $n }}/claims" class="flex flex-col gap-4" enctype="multipart/form-data">
                        @csrf
                        <x-ui.field name="amount" label="Сколько, ₽" :value="rtrim(rtrim(number_format($invoice->remaining(), 2, ',', ''), '0'), ',')" inputmode="decimal"/>
                        <x-ui.field name="paid_at" label="Когда" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"/>
                        <x-ui.field name="slip" label="Платёжка" type="file" accept="image/*,application/pdf"/>
                        <x-ui.button type="submit" variant="primary" block>Отправить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
    </div>
</div>
