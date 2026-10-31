{{-- Форма площадки: название, город, адрес, мест (без схемы), заметки, «работает»; без $yard — новая. Ряды и места — в редакторе схемы.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
<form method="post" action="{{ $yard ? '/yards/'.$yard->id : '/yards' }}" class="flex flex-col gap-3">
    @csrf @if ($yard) @method('put') @endif
    <div class="fields">
        <x-ui.field name="name" label="Название" :value="$yard?->name" required/>
        <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" create="/reference/settlements" :value="$yard?->settlement_id" :text="$yard?->settlement?->title()"/>
        <x-ui.field name="address" label="Адрес" :value="$yard?->address"/>
        {{-- Со схемой мест столько, сколько на ней (`SavePlan`). --}}
        @unless ($yard?->rows)<x-ui.field name="capacity" label="Мест" :value="$yard?->capacity"/>@endunless
        @if ($yard)<x-ui.field name="notes" label="Заметки" type="textarea" :value="$yard->notes"/>@endif
    </div>
    @if ($yard)<x-ui.check name="is_active" :checked="$yard->is_active">Работает</x-ui.check>@endif
    <x-ui.sheet-foot>{{ $yard ? 'Сохранить' : 'Добавить' }}</x-ui.sheet-foot>
</form>
@if ($yard && ! $yard->stored_vehicles_count)<form method="post" action="/yards/{{ $yard->id }}" class="mt-2" data-turbo-confirm="Убрать парковку «{{ $yard->name }}»?">@csrf @method('delete')<x-ui.button variant="ghost" block>Убрать</x-ui.button></form>@endif
