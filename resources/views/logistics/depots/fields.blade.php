{{-- Поля стоянки — одни на «Добавить» и правку. --}}
<form method="post" action="{{ $action }}" class="flex flex-col gap-4">
    @csrf
    @if ($depot->exists) @method('PUT') @endif
    <div class="fields">
        <x-ui.field name="name" id="depot-name-{{ $id }}" label="Название" :value="$depot->name" placeholder="Например: Киров, Северная" required/>
        <x-ui.field name="city" id="depot-city-{{ $id }}" label="Город" :value="$depot->settlement?->name"/>
        <x-ui.field name="address" id="depot-address-{{ $id }}" label="Адрес" type="textarea" :value="$depot->address"/>
        <x-ui.field name="contact_name" id="depot-contact-{{ $id }}" label="Кто на месте" :value="$depot->contact_name"/>
        <x-ui.field name="phone" id="depot-phone-{{ $id }}" label="Телефон" type="tel" :value="$depot->phone ? \App\Support\Phone::format($depot->phone) : null"/>
        <x-ui.field name="note" id="depot-note-{{ $id }}" label="Заметка" type="textarea" :value="$depot->note"/>
    </div>
    <x-ui.sheet-foot>{{ $depot->exists ? 'Сохранить' : 'Добавить' }}</x-ui.sheet-foot>
</form>
