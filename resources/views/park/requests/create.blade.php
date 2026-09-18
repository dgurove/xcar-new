@php use App\Park\RequestType; @endphp
<x-ui.shell title="Новая заявка" narrow>
    <x-ui.pills class="mb-6">
        @foreach (RequestType::cases() as $t)
            <x-ui.pill :href="'/requests/new?tip='.$t->value.($vehicle ? '&mashina='.$vehicle->id : '')" :current="$type === $t">{{ $t->label() }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    <form method="post" action="/requests" id="request-form" data-controller="vin draft" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">
        @if ($type === RequestType::Intake && !$vehicle)
            <x-ui.card title="Транспортное средство">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field name="ref" label="Номер убытка" class="sm:col-span-2"/>
                    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric"/>
                    <x-ui.field name="plate" label="Госномер" autocapitalize="characters"/>
                    <x-ui.vin/>
                    <x-ui.field name="color" label="Цвет"/>
                    <x-ui.field name="vendor_id" label="Заказчик" :options="$vendors" placeholder="—"/>
                    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—"/>
                </div>
            </x-ui.card>
        @else
            <x-ui.card title="Транспортное средство">
                <x-ui.combobox name="vehicle_id" label="Номер убытка, VIN или госномер" url="/reference/cars" :value="$vehicle?->id" :text="$vehicle?->titleWithYear()"/>
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
