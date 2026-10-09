{{-- Шторка «ТС» поиском (`car_pick_controller`): поле сверху, машины системы строками, выбранная с галкой, «Нет в
     системе: „…“» — словами. Два места (09.10.2026):
     — карточка ручной ссылки (action): своя форма, кнопка внизу называет исход (`DealCredit::outcome` по preview),
       «Отвязать» красной строкой, если ТС уже есть; открывают событием (атрибуты на обёртку);
     — «Создать ссылку» (embedded): скрытые поля — в форме снаружи, строка «ТС» (слот trigger с целью label) открывает
       лист, выбор закрывает его.
     Поле поиска ни к какой форме не относится (form="car-pick-none"): набранное не делает лист «несохранённым». --}}
@props(['id', 'action' => null, 'preview' => null, 'offerId' => null, 'text' => null, 'clear' => false, 'trigger' => null])
@php $embedded = ! $action; @endphp
<div class="contents" data-controller="sheet car-pick" data-car-pick-url-value="/work/money/cars" @if ($preview) data-car-pick-preview-value="{{ $preview }}" @endif
     @if ($embedded) data-car-pick-embedded-value="true" @endif @if ($offerId) data-car-pick-label-value="{{ $text }}" @endif {{ $attributes }}>
    {{ $trigger }}
    @if ($embedded)
        <input type="hidden" name="offer_id" value="{{ $offerId }}" data-car-pick-target="offer">
        <input type="hidden" name="car_title" value="{{ $offerId ? '' : $text }}" data-car-pick-target="text">
    @endif
    <template data-car-pick-target="check"><x-ui.icon name="check" class="size-5 shrink-0 text-accent-text"/></template>
    <x-ui.sheet :id="$id" title="ТС" class="sheet-pick">
        {{-- С формой — она на весь лист: кнопка внизу (.sheet-foot) липнет к низу листа, а не стоит за списком. --}}
        @unless ($embedded)<form method="post" action="{{ $action }}" class="flex flex-col" data-sheet-clean>@csrf @method('put')@endunless
        <label class="toolbar-search">
            <x-ui.icon name="search" class="size-4 shrink-0 text-ink-muted"/>
            <input type="search" form="car-pick-none" placeholder="Номер, ДЛ, VIN или марка" autocomplete="off" enterkeyhint="search" aria-label="Поиск ТС" autofocus
                   data-car-pick-target="query" data-action="focus->car-pick#load input->car-pick#search keydown->car-pick#key">
        </label>
        <div class="list mt-3" data-car-pick-target="results" hidden></div>
        @unless ($embedded)
                <input type="hidden" name="offer_id" value="{{ $offerId }}" data-car-pick-target="offer">
                <input type="hidden" name="car_title" value="{{ $offerId ? '' : $text }}" data-car-pick-target="text">
                @if ($clear)
                    <div class="list mt-3"><button class="row row-danger" name="clear" value="1">Отвязать</button></div>
                @endif
                <div class="sheet-foot mt-4"><x-ui.button block data-car-pick-target="submit">Сохранить</x-ui.button></div>
            </form>
        @endunless
    </x-ui.sheet>
</div>
