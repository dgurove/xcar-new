{{-- Заявка логиста от своего заказчика (18.10.2026, владелец: «удобно создавать заявки на вывоз от других, не только от
     xcar»; вечером: «с дизайном и удобством схалтурил»). Страницей, поля строками группами, как в Настройках. Телефон —
     одной колонкой; ПК — две: слева текст кучей, ТС и маршрут, справа липко заказчик, исполнитель, деньги с «останется вам»
     и кнопка. Заказчик и исполнитель — шторкой выбора `x-people.pick`: свои строками (у исполнителя — город и сколько у него
     в работе), «+ Новый …» тут же, без ухода со страницы; выбрали заказчика — его адрес встаёт в «Откуда», если пусто.
     Текст кучей (WhatsApp, письмо) — `CarText` раскладывает марку, модель, VIN, госномер, адрес и телефон. Правка — та же. --}}
@php
    use App\Support\Money;
    use App\Support\People;
    $car = $t->car;
    $me = auth()->user();
    $action = $editing ? '/transports/'.$t->id : '/transports';
    $sum = fn ($v) => $v !== null ? Money::nums((int) $v) : null;
    $clients = \App\Logistics\Client::of($me)->whereNull('archived_at')->withCount('transports')->orderByDesc('transports_count')->orderBy('name')->get()
        ->map(fn ($c) => People::row($c) + ['address' => $c->address]);
@endphp
<x-ui.shell :title="$editing ? 'Изменить заявку' : 'Новая заявка'" :back="['Вывоз', '/transports']">
    <form method="post" action="{{ $action }}" id="order-form" class="order-form" data-controller="order-form" data-action="people:picked->order-form#picked input->order-form#money">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="order-main flex flex-col gap-6">
            @unless ($editing)<div class="fields"><x-ui.paste placeholder="Марка, модель, госномер, адрес, телефон"/></div>@endunless

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
                    <x-ui.field name="from_address" label="Адрес" type="textarea" :value="$t->from_address" required data-order-form-target="from"/>
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
            <div class="fields"><x-ui.field name="comment" label="Комментарий" type="textarea" :value="$t->comment"/></div>
        </div>

        <aside class="order-side flex flex-col gap-6">
            <section class="flex flex-col gap-2">
                <h2 class="list-head">Заказчик и исполнитель</h2>
                <div class="fields">
                    <x-people.pick name="client_id" label="Заказчик" kind="client" :people="$clients" :value="$t->client_id" title="Заказчик" required/>
                    <x-people.pick name="executor_id" label="Вывозит" kind="contractor" :people="People::contractorsOf($me)" :value="$t->executor_id" title="Кто вывозит" none="Пока никто" link/>
                </div>
            </section>
            <section class="flex flex-col gap-2">
                <h2 class="list-head">Деньги</h2>
                <div class="fields">
                    <x-ui.field name="client_price" label="Заказчику" :value="$sum($t->client_price)" placeholder="₽" data-order-form-target="price"/>
                    <x-ui.field name="executor_cost" label="Исполнителю" :value="$sum($t->executor_cost)" placeholder="₽" data-order-form-target="cost"/>
                    <x-ui.field name="distance_km" label="Расстояние, км" :value="$t->distance_km"/>
                </div>
                <p class="px-4 text-sm text-ink-muted" data-order-form-target="margin" hidden></p>
            </section>
            <x-ui.button type="submit" class="order-submit w-full">{{ $editing ? 'Сохранить' : 'Создать заявку' }}</x-ui.button>
        </aside>

        <div class="lg:hidden">
            <x-ui.action-bar><x-ui.button type="submit" form="order-form" class="min-w-0 flex-1">{{ $editing ? 'Сохранить' : 'Создать заявку' }}</x-ui.button></x-ui.action-bar>
        </div>
    </form>
</x-ui.shell>
