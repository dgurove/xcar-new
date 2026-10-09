{{-- Шторка «Даты»: день приёма и выдачи (`SetDates`, POST /cars/{v}/dates; владелец 09.10.2026: «по письмам расставили, а
     фактические даты другие» — раньше только отменой приёма). Открывает сама дата: метка «принята …» в карточке строки и
     дата шага «Принята» / «Выдана» в деле (событие dates:open). Время дня остаётся прежним. --}}
@if ($vehicle->accepted_at && auth()->user()?->canManagePark())
    <div data-controller="sheet" data-action="dates:open@window->sheet#open" class="contents">
        <x-ui.sheet id="dates-{{ $vehicle->id }}" title="Даты" :open="$errors->hasAny(['accepted_at', 'released_at'])">
            <form method="post" action="/cars/{{ $vehicle->id }}/dates" class="flex flex-col gap-3">
                @csrf
                <div class="fields">
                    <x-ui.field name="accepted_at" label="Принята" type="date" required :value="old('accepted_at', $vehicle->accepted_at->toDateString())" :max="now()->toDateString()"/>
                    @if ($vehicle->released_at)<x-ui.field name="released_at" label="Выдана" type="date" required :value="old('released_at', $vehicle->released_at->toDateString())" :max="now()->toDateString()"/>@endif
                </div>
                <div class="sheet-foot"><x-ui.button block>Сохранить</x-ui.button></div>
            </form>
        </x-ui.sheet>
    </div>
@endif
