{{-- Поля расхода: что, сколько, когда. Подсказки заполняют «что» одним нажатием — печатать не обязательно. --}}
<div data-controller="suggest">
    <x-ui.field name="title" label="Что" :value="$cost?->title" placeholder="Доставка" autocomplete="off" data-suggest-target="field"/>
    @unless ($cost)
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach (['Доставка', 'Запчасти', 'Работы', 'Покраска', 'Шины', 'Документы'] as $hint)
                <button type="button" class="chip" data-action="suggest#fill" data-suggest-value="{{ $hint }}">{{ $hint }}</button>
            @endforeach
        </div>
    @endunless
</div>
<x-ui.field name="amount" label="Сколько, ₽" :value="$cost?->amount ? rtrim(rtrim(number_format($cost->amount, 2, ',', ''), '0'), ',') : null" placeholder="15000"/>
<x-ui.field name="spent_at" label="Когда" type="date" :value="($cost?->spent_at ?? now())->toDateString()" max="{{ now()->toDateString() }}"/>
