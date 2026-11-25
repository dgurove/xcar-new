{{-- Шторка оплаты группы «Счёт и подбор» (25.11.2026, владелец: «„Оплата получена…“ и там выбор, за что оплата получена, в какой
     форме и сколько», «и потом чтобы редактировать можно было»; «почему оба галками отмечены — ничего не понимаю» → одна оплата за
     раз). «За что» — счёт за ТС или подбор, под ним «осталось N ₽» выбранного; «Как» — безнал или наличными; «Сколько» — с лаймовым
     «Всё» (остаток выбранного); «Когда». Без `payment` — новая оплата (`POST …/bills/paid`), с ним — правка вписанной руками
     (`PUT …/payments/{p}`) и «Удалить оплату» (`DELETE`). `bills` — ['sale' => счёт, 'selection' => ссылка]. --}}
@props(['deal', 'bills', 'id', 'payment' => null])
@php
    use App\Support\Money; use App\Billing\InvoiceState;
    $names = ['sale' => 'Счёт за ТС', 'selection' => 'Подбор'];
    $current = $payment ? collect($bills)->search(fn ($i) => $i->id === $payment->invoice_id) : null;
    // Сколько можно записать: остаток; при правке своего счёта — и сама оплата (она погаснет перед новой).
    $left = collect($bills)->map(fn ($i) => (int) round($i->remaining()));
    // «Всё» при правке — остаток вместе с этой оплатой (она погаснет перед новой); на экране — настоящий остаток.
    $whole = $left->map(fn ($v, $k) => $v + ($payment && $k === $current ? (int) round((float) $payment->amount) : 0));
    $options = $whole->filter(fn ($v, $k) => $v > 0 || $k === $current)->keys()->mapWithKeys(fn ($k) => [$k => $names[$k]])->all();
    $for = old('_sheet') === $id ? old('for', $current ?? array_key_first($options)) : ($current ?? array_key_first($options));
    $source = old('_sheet') === $id ? old('source', $payment?->source->value ?? 'bank') : ($payment?->source->value ?? 'bank');
    $amount = old('_sheet') === $id ? old('amount') : ($payment ? Money::nums((int) $payment->amount) : null);
    $date = old('_sheet') === $id ? old('paid_at') : ($payment?->paid_at ?? now('Europe/Moscow'))->format('Y-m-d');
    $action = $payment ? '/work/deals/'.$deal->id.'/payments/'.$payment->id : '/work/deals/'.$deal->id.'/bills/paid';
@endphp
<x-ui.sheet :id="$id" :title="$payment ? 'Оплата' : 'Оплата получена'">
    <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-controller="payment-sheet" data-payment-sheet-left-value="{{ $left->toJson() }}" data-payment-sheet-whole-value="{{ $whole->toJson() }}">
        @csrf
        @if ($payment)@method('put')@endif
        <div class="flex flex-col gap-1.5">
            <span class="field-label">За что</span>
            @if (count($options) > 1)
                <x-ui.segment name="for" :options="$options" :value="$for" data-action="change->payment-sheet#pick"/>
            @else
                <input type="hidden" name="for" value="{{ $for }}">
                <span>{{ $options[$for] ?? '' }}</span>
            @endif
            <span class="text-sm text-ink-muted">осталось <span class="nums" data-payment-sheet-target="left">{{ Money::rub($left[$for] ?? 0) }}</span></span>
            @error('for')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="flex flex-col gap-1.5">
            <span class="field-label">Как</span>
            <x-ui.segment name="source" :options="['bank' => 'Безнал', 'cash' => 'Наличными']" :value="$source"/>
        </div>
        <div class="field @error('amount') field-invalid @enderror">
            <div class="flex items-baseline justify-between gap-3">
                <label for="{{ $id }}-amount" class="field-label">Сколько, ₽</label>
                <button type="button" class="text-sm text-accent-text" data-action="payment-sheet#all">Всё</button>
            </div>
            <input id="{{ $id }}-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" class="field-input nums" value="{{ $amount }}"
                data-controller="digits" data-digits-decimals-value="0" data-action="input->digits#format" data-payment-sheet-target="amount">
            @error('amount')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <x-ui.field name="paid_at" type="date" label="Когда" :value="$date" :id="$id.'-date'"/>
        <x-ui.sheet-foot>{{ $payment ? 'Сохранить' : 'Записать' }}</x-ui.sheet-foot>
    </form>
    @if ($payment)
        <form method="post" action="{{ $action }}" class="mt-2" {!! \App\Offers\Warnings::html('Оплата '.Money::rub((float) $payment->amount).' удалится из сделки', 'Да, удалить', danger: true) !!}>
            @csrf @method('delete')
            <button type="submit" class="btn btn-ghost w-full text-danger">Удалить оплату</button>
        </form>
    @endif
</x-ui.sheet>
