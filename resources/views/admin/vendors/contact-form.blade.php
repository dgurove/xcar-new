@php use App\Vendors\ContactRole; @endphp
<form method="post" action="{{ $action }}" class="flex flex-col gap-4">
    @csrf @if ($method !== 'post') @method($method) @endif
    <input type="hidden" name="contact_id" value="{{ $contact?->id }}">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="name" label="Имя" :value="$contact?->name" required span="col-span-2"/>
        <x-ui.field name="title" label="Должность" :value="$contact?->title" span="col-span-2"/>
        <x-ui.field name="role" label="По какому вопросу" :options="ContactRole::options()" :value="$contact?->role->value ?? 'claims'" span="col-span-2"/>
        <x-ui.field name="phone" label="Телефон" type="tel" :value="$contact?->phone"/>
        <x-ui.field name="email" label="Почта" type="email" :value="$contact?->email"/>
        <x-ui.check name="is_default" :checked="$contact?->is_default ?? false">Основной</x-ui.check>
        <x-ui.check name="always_cc" :checked="$contact?->always_cc ?? false">Всегда в копии</x-ui.check>
        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$contact?->notes" span="col-span-2"/>
    </div>
    <x-ui.button block>Сохранить</x-ui.button>
</form>
