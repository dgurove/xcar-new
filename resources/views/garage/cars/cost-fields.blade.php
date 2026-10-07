{{-- Поля расхода: сколько, что, когда. Подсказки заполняют «что» одним нажатием — печатать не обязательно.
     07.10.2026: сумма первой (у нового расхода — с клавиатурой сразу), поля строками (.fields), подсказки одной строкой вбок. --}}
<div class="contents" data-controller="suggest">
    <div class="fields">
        <x-ui.field name="amount" label="Сколько, ₽" :value="$cost?->amount ? \App\Support\Money::nums($cost->amount, fmod((float) $cost->amount, 1) ? 2 : 0) : null" placeholder="15 000" data-controller="digits" data-digits-decimals-value="2" data-action="input->digits#format" :autofocus="! $cost"/>
    </div>
    @unless ($cost)
        <div class="sheet-chips">
            {{-- Пока машину везут — первым эвакуатор: это и есть расход этапа. --}}
            @foreach (isset($car) && $car->isWaiting() ? ['Эвакуатор', 'Доставка', 'Документы'] : ['Запчасти', 'Работы', 'Покраска', 'Шины', 'Эвакуатор', 'Документы'] as $hint)
                <button type="button" class="chip" data-action="suggest#fill" data-suggest-value="{{ $hint }}">{{ $hint }}</button>
            @endforeach
        </div>
    @endunless
    <div class="fields">
        <x-ui.field name="title" label="Что" :value="$cost?->title" placeholder="Доставка" autocomplete="off" data-suggest-target="field"/>
        <x-ui.field name="spent_at" label="Когда" type="date" :value="($cost?->spent_at ?? now())->toDateString()" max="{{ now()->toDateString() }}"/>
    </div>
</div>
{{-- Кто платил — выбирает сотрудник: свой расход или менеджера с его слов. «Оплата поставщику» — всегда менеджера. --}}
@if (($staff ?? false) && ($car ?? $cost?->car)?->manager_id && ! $cost?->isSupplier())
    @php $paid = old('paid_by', $cost?->payer?->value ?? 'xcar'); @endphp
    <div class="flex flex-col gap-1.5">
        <span class="field-label">Платили</span>
        <div class="flex flex-wrap gap-2">
            <label class="choice"><input type="radio" name="paid_by" value="xcar" @checked($paid === 'xcar')><span>Мы</span></label>
            <label class="choice"><input type="radio" name="paid_by" value="manager" @checked($paid === 'manager')><span>Менеджер</span></label>
        </div>
    </div>
@endif
