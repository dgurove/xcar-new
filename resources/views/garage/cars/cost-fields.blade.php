{{-- Поля расхода: что, сколько, когда. Подсказки заполняют «что» одним нажатием — печатать не обязательно. --}}
<div data-controller="suggest">
    <x-ui.field name="title" label="Что" :value="$cost?->title" placeholder="Доставка" autocomplete="off" data-suggest-target="field"/>
    @unless ($cost)
        <div class="mt-2 flex flex-wrap gap-1.5">
            {{-- На доставке первым — эвакуатор: это и есть расход этапа. --}}
            @foreach (isset($car) && $car->state === \App\Garage\CarState::Delivery ? ['Эвакуатор', 'Доставка', 'Документы'] : ['Запчасти', 'Работы', 'Покраска', 'Шины', 'Доставка', 'Документы'] as $hint)
                <button type="button" class="chip" data-action="suggest#fill" data-suggest-value="{{ $hint }}">{{ $hint }}</button>
            @endforeach
        </div>
    @endunless
</div>
<x-ui.field name="amount" label="Сколько, ₽" :value="$cost?->amount ? \App\Support\Money::nums($cost->amount, fmod((float) $cost->amount, 1) ? 2 : 0) : null" placeholder="15 000" data-controller="digits" data-digits-decimals-value="2" data-action="input->digits#format"/>
<x-ui.field name="spent_at" label="Когда" type="date" :value="($cost?->spent_at ?? now())->toDateString()" max="{{ now()->toDateString() }}"/>
