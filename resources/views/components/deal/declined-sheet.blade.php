{{-- «Поставщик отказал…» (28.10.2026, владелец про 812865/2026: Совкомбанк продал сам — «не в архив, а как завершённое по
     такой-то причине»): причина (чипы подставляют частую), вывоз — если машину ещё не забрали: у вендора, чьи машины вывозим
     все (Совкомбанк, контракт), по умолчанию остаётся. `POST /work/deals/{id}/declined` → `DeclineBySupplier`. Открывает
     событие declined:open (красная строка карточки сделки, «Действия» окошка, «···» редактора). --}}
@props(['deal'])
@php
    $offer = $deal->offer;
    $pickup = $offer ? \App\Logistics\Transport::openFor($offer) : null;
    $askPickup = $pickup && ! $pickup->taken();
    $keep = (bool) $offer?->vendor?->pickup_on_arrival;
@endphp
<div data-controller="sheet" data-action="declined:open@window->sheet#open" class="contents">
    <x-ui.sheet id="declined-{{ $deal->id }}" title="Поставщик отказал">
        <form method="post" action="/work/deals/{{ $deal->id }}/declined" class="flex flex-col gap-4"
              data-turbo-confirm="Закрыть сделку: отказ поставщика?" data-turbo-confirm-text="Предложение уйдёт в архив, неоплаченные счета аннулируются, менеджеру придёт уведомление" data-turbo-confirm-label="Закрыть">
            @csrf
            <div class="sheet-chips">
                @foreach (['Поставщик продаёт сам', 'Снял с продажи', 'Передумал продавать'] as $why)
                    <button type="button" class="chip" onclick="const f = this.closest('form').querySelector('textarea'); f.value = '{{ $why }}'; f.focus()">{{ $why }}</button>
                @endforeach
            </div>
            <div class="fields">
                <x-ui.field type="textarea" name="note" :id="'declined-note-'.$deal->id" label="Почему" :value="old('note')" rows="2" required/>
            </div>
            @if ($askPickup)
                <div>
                    <h3 class="list-head">Вывоз</h3>
                    <x-ui.segment name="pickup" :options="['keep' => 'Вывозим', 'drop' => 'Не нужен']" :value="old('pickup', $keep ? 'keep' : 'drop')"/>
                    <p class="mt-2 text-sm text-ink-muted">{{ $keep ? 'По контракту машину вывозим всё равно, покупатель поставщика заберёт её у нас' : 'Не нужен — задача вывоза отменится' }}</p>
                </div>
            @endif
            <x-ui.sheet-foot type="submit" variant="danger">Закрыть сделку</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
