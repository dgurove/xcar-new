{{-- Заявка логиста от своего заказчика (18.10.2026, владелец: «удобно создавать заявки на вывоз от других, не только от
     xcar»): страницей, поля строками группами, как в Настройках. Первым — текст кучей (WhatsApp, письмо): `CarText`
     раскладывает марку, модель, VIN, госномер, адрес и телефон по полям. Заказчик — свой справочник с «Добавить «…»»;
     исполнитель — свои и сама, последней строкой «+ Исполнитель» (шторка, вернёт сюда). Правка — та же форма. --}}
@php
    use App\Support\Money;
    $car = $t->car;
    $action = $editing ? '/transports/'.$t->id : '/transports';
    $sum = fn ($v) => $v !== null ? Money::nums((int) $v) : null;
@endphp
<x-ui.shell :title="$editing ? 'Изменить заявку' : 'Новая заявка'" :back="['Вывоз', '/transports']" narrow>
    {{-- Черновик: «Новый исполнитель» уходит со страницы и возвращает сюда — набранное восстанавливается, исполнитель встаёт новый. --}}
    <form method="post" action="{{ $action }}" class="flex flex-col gap-6" id="order-form" @unless ($editing) data-controller="draft" data-draft-key-value="order-new" @endunless>
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="fields">
            @unless ($editing)<x-ui.paste placeholder="Марка, модель, госномер, адрес, телефон"/>@endunless
            <x-ui.combobox name="client_id" label="Заказчик" url="/reference/clients" create="/reference/clients" :value="$t->client_id" :text="$t->client?->name" required placeholder="Название"/>
        </div>

        <section class="flex flex-col gap-2">
            <h2 class="list-head">ТС</h2>
            <div class="fields">
                <x-cars.brand-model :brand-id="old('brand_id', $car?->brand_id)" :brand-text="$car?->brand?->name" :model-id="old('model_id', $car?->model_id)" :model-text="$car?->model?->name" required/>
                <x-ui.field name="plate" label="Госномер" :value="$car?->plate"/>
                <x-ui.field name="vin" label="VIN" :value="$car?->vin"/>
                <x-ui.field name="year" label="Год" :value="$car?->year"/>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="list-head">Откуда</h2>
            <div class="fields">
                <x-ui.field name="from_address" label="Адрес" type="textarea" :value="$t->from_address" required/>
                <x-ui.field name="from_contact" label="У кого забрать" :value="$t->from_contact"/>
                <x-ui.field name="from_phone" label="Телефон" type="tel" :value="$t->from_phone ? \App\Support\Phone::format($t->from_phone) : null"/>
                <x-ui.field name="pickup_at" label="Когда" type="datetime-local" :value="$t->pickup_at?->format('Y-m-d\TH:i')" default-time="10:00"/>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="list-head">Куда</h2>
            <div class="fields">
                <x-ui.field name="to_address" label="Адрес" type="textarea" :value="$t->to_address" required/>
                <x-ui.field name="to_contact" label="Кто принимает" :value="$t->to_contact"/>
                <x-ui.field name="to_phone" label="Телефон" type="tel" :value="$t->to_phone ? \App\Support\Phone::format($t->to_phone) : null"/>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="list-head">Деньги и исполнитель</h2>
            <div class="fields">
                <x-ui.field name="client_price" label="Цена заказчику" :value="$sum($t->client_price)" placeholder="₽"/>
                <x-ui.field name="executor_id" label="Кто вывозит" :options="$executors" :value="$t->executor_id" placeholder="Пока никто"/>
                <x-ui.field name="executor_cost" label="Цена исполнителю" :value="$sum($t->executor_cost)" placeholder="₽"/>
                <x-ui.field name="distance_km" label="Расстояние, км" :value="$t->distance_km"/>
                <x-ui.field name="comment" label="Комментарий" type="textarea" :value="$t->comment"/>
            </div>
        </section>

        <x-ui.action-bar>
            <x-ui.button type="submit" form="order-form" class="min-w-0 flex-1">{{ $editing ? 'Сохранить' : 'Создать заявку' }}</x-ui.button>
        </x-ui.action-bar>
    </form>
    {{-- Вне формы заявки: у исполнителя своя форма, вложенную браузер выбросил бы. --}}
    <div class="list mt-2"><x-logistics.contractor-new back="order" label="Новый исполнитель"/></div>
</x-ui.shell>
