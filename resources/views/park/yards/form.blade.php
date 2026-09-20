{{-- Форма площадки: название, город, адрес, мест, ряды, заметки, «работает»; без $yard — новая. --}}
<form method="post" action="{{ $yard ? '/yards/'.$yard->id : '/yards' }}" class="flex flex-col gap-4">
    @csrf @if ($yard) @method('put') @endif
    <x-ui.field name="name" label="Название" :value="$yard?->name" required :autofocus="! $yard"/>
    <x-ui.field name="settlement_id" label="Город" :options="$settlements" placeholder="—" :value="$yard?->settlement_id"/>
    <x-ui.field name="address" label="Адрес" :value="$yard?->address"/>
    <x-ui.field name="capacity" label="Мест" inputmode="numeric" :value="$yard?->capacity"/>
    <x-ui.field name="rows" label="Ряды: буква и число мест, по строке" type="textarea" :value="$yard ? implode(PHP_EOL, array_map(fn ($r) => trim(($r['name'] ?? '').': '.($r['n'] ?? 0), ': '), $yard->rows ?? [])) : null" placeholder="A: 20"/>
    @if ($yard)
        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$yard->notes"/>
        <x-ui.check name="is_active" :checked="$yard->is_active">Работает</x-ui.check>
    @endif
    <x-ui.button block>{{ $yard ? 'Сохранить' : 'Добавить' }}</x-ui.button>
</form>
@if ($yard && ! $yard->stored_vehicles_count)<form method="post" action="/yards/{{ $yard->id }}" class="mt-2" data-turbo-confirm="Убрать площадку «{{ $yard->name }}»?">@csrf @method('delete')<x-ui.button variant="ghost" block>Убрать</x-ui.button></form>@endif
