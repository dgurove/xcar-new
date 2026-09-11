@php use App\Park\RequestType; @endphp
<x-ui.shell title="Новая заявка" :trail="[['Стоянка', '/'], ['Заявки', '/zayavki'], ['Новая']]" narrow>
    <div class="mb-4 presets">
        @foreach (RequestType::cases() as $t)
            <a href="/zayavki/novaya?tip={{ $t->value }}{{ $vehicle ? '&mashina='.$vehicle->id : '' }}" class="preset" @if ($type === $t) aria-current="true" @endif>{{ $t->label() }}</a>
        @endforeach
    </div>
    <form method="post" action="/zayavki" id="request-form" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">
        @if ($type === RequestType::Intake && !$vehicle)
            <x-ui.card title="Машина">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field name="ref" label="Номер убытка" class="sm:col-span-2"/>
                    <x-ui.combobox name="brand_id" label="Марка" url="/spravochnik/marki" create="/spravochnik/marki" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/spravochnik/modeli" create="/spravochnik/modeli" depends="#f-brand_id"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric"/>
                    <x-ui.field name="plate" label="Госномер" autocapitalize="characters"/>
                    <x-ui.field name="vin" label="VIN" maxlength="17" class="uppercase" autocapitalize="characters"/>
                    <x-ui.field name="color" label="Цвет"/>
                    <x-ui.field name="client_id" label="Заказчик" :options="$clients" placeholder="—"/>
                </div>
            </x-ui.card>
        @else
            <x-ui.card title="Машина">
                <x-ui.combobox name="vehicle_id" label="Номер убытка, VIN или госномер" url="/spravochnik/mashiny" :value="$vehicle?->id" :text="$vehicle?->titleWithYear()"/>
            </x-ui.card>
        @endif
        <x-ui.card title="Заявка">
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($type === RequestType::Move)
                    <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—"/>
                @endif
                <x-ui.field name="planned_at" label="Когда" type="datetime-local"/>
                <x-ui.field name="contact" label="Контакт" placeholder="Имя, телефон"/>
                <x-ui.field name="note" label="Заметка" type="textarea" class="sm:col-span-2"/>
            </div>
        </x-ui.card>
    </form>
    <x-ui.action-bar><x-ui.button form="request-form" class="min-w-0 flex-1">Завести</x-ui.button></x-ui.action-bar>
</x-ui.shell>
