{{-- Поля заказчика — одни на «Добавить» и правку. --}}
<form method="post" action="{{ $action }}" class="flex flex-col gap-4">
    @csrf
    @if ($client->exists) @method('PUT') @endif
    <x-ui.segment name="kind" :options="\App\Logistics\Client::KINDS" :value="old('kind', $client->kind)"/>
    <div class="fields">
        <x-ui.field name="name" id="client-name-{{ $id }}" label="Название" :value="$client->name" required/>
        <x-ui.field name="contact_name" id="client-contact-{{ $id }}" label="Контакт" :value="$client->contact_name"/>
        <x-ui.field name="phone" id="client-phone-{{ $id }}" label="Телефон" type="tel" :value="$client->phone ? \App\Support\Phone::format($client->phone) : null"/>
        <x-ui.field name="email" id="client-email-{{ $id }}" label="Почта" type="email" :value="$client->email"/>
        <x-ui.field name="inn" id="client-inn-{{ $id }}" label="ИНН" :value="$client->inn"/>
        <x-ui.field name="address" id="client-address-{{ $id }}" label="Откуда обычно забирать" type="textarea" :value="$client->address"/>
        <x-ui.field name="note" id="client-note-{{ $id }}" label="Заметка" type="textarea" :value="$client->note"/>
    </div>
    <x-ui.sheet-foot>{{ $client->exists ? 'Сохранить' : 'Добавить' }}</x-ui.sheet-foot>
</form>
