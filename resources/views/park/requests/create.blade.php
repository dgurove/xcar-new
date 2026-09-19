{{-- Новая заявка. С кандидатом из письма поля предзаполнены, письма — рядом с формой (справа от 1024,
     на телефоне — шторка «Письма»), чтобы сверять, не уходя со страницы. Тип «приём» / «эвакуация» решает поле «Доставка». --}}
@php use App\Park\{RequestType, Delivery}; $p = $p ?? []; $val = fn ($k) => old($k, $p[$k] ?? null); $letters = $candidate !== null; @endphp
<x-ui.shell title="Новая заявка" :narrow="!$letters">
    <div class="{{ $letters ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]' : '' }}">
    <div class="min-w-0">
    @unless ($letters)
        <x-ui.pills class="mb-6">
            @foreach (RequestType::cases() as $t)
                <x-ui.pill :href="'/requests/new?type='.$t->value.($vehicle ? '&car='.$vehicle->id : '')" :current="$type === $t">{{ $t->label() }}</x-ui.pill>
            @endforeach
        </x-ui.pills>
    @endunless
    <form method="post" action="/requests" id="request-form" data-controller="vin draft" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">
        @if ($candidate)
            <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
            @foreach ((array) ($p['flags'] ?? []) as $f)<input type="hidden" name="flags[]" value="{{ $f }}">@endforeach
            @foreach ((array) ($p['docs_required'] ?? []) as $d)<input type="hidden" name="docs_required[]" value="{{ $d }}">@endforeach
            @if ($p['value'] ?? null)<input type="hidden" name="value" value="{{ $p['value'] }}">@endif
            @if ($vehicle)
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">ТС уже заведена — письма привяжутся к ней</x-ui.pill>
                    <a href="/cars/{{ $vehicle->id }}" class="chip">{{ $vehicle->titleWithYear() }}@if ($vehicle->ref) <span class="nums text-ink-muted">{{ $vehicle->ref }}</span>@endif</a>
                </div>
            @endif
        @endif
        @if (in_array($type, [RequestType::Intake, RequestType::Tow], true) && !$vehicle)
            <x-ui.card title="Транспортное средство">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field name="ref" label="Номер убытка" :value="$val('ref')" class="sm:col-span-2"/>
                    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" resets="#cb-model_id" :value="$p['brand']?->id ?? null" :text="$p['brand']?->name ?? null"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="$p['model']?->id ?? null" :text="$p['model']?->name ?? null"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$val('year')"/>
                    <x-ui.field name="plate" label="Госномер" autocapitalize="characters" :value="$val('plate')"/>
                    <x-ui.vin :value="$val('vin')"/>
                    <x-ui.field name="color" label="Цвет" :value="$val('color')"/>
                    <x-ui.field name="vendor_id" label="Заказчик" :options="$vendors" placeholder="—" :value="$val('vendor_id')"/>
                    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—" :value="$val('category')"/>
                </div>
            </x-ui.card>
        @else
            <x-ui.card title="Транспортное средство">
                <x-ui.combobox name="vehicle_id" label="Номер убытка, VIN или госномер" url="/reference/cars" :value="$vehicle?->id" :text="$vehicle?->titleWithYear()"/>
            </x-ui.card>
        @endif
        <x-ui.card title="Заявка">
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($type === RequestType::Intake)
                    {{-- Эвакуатор или сам — узнаём по телефону; «ещё не знаем» оставляет заявку в «Связаться». --}}
                    <div class="field sm:col-span-2">
                        <span class="field-label">Доставка</span>
                        <div class="flex flex-wrap gap-1.5">
                            <label class="choice"><input type="radio" name="delivery" value="" @checked(!$val('delivery'))><span>Ещё не знаем</span></label>
                            @foreach (Delivery::cases() as $d)
                                <label class="choice"><input type="radio" name="delivery" value="{{ $d->value }}" @checked($val('delivery') === $d->value)><span>{{ $d->label() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($type === RequestType::Move)
                    <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—"/>
                @endif
                <x-ui.field name="planned_at" label="Когда" type="datetime-local"/>
                @if ($type === RequestType::Tow || $letters)
                    <x-ui.field name="from_address" label="Откуда" :value="$val('from_address') ?? $vehicle?->offer?->inspection_address"/>
                @endif
                <x-ui.field name="contact_name" :label="$type === RequestType::Tow || $letters ? 'Страхователь' : 'Кто сдаёт'" :value="$val('contact_name') ?? $vehicle?->contact_name"/>
                <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$val('contact_phone') ?? $vehicle?->contact_phone"/>
                <x-ui.field name="note" label="Заметка" type="textarea" class="sm:col-span-2" :value="$val('note')"/>
            </div>
        </x-ui.card>
    </form>
    </div>
    @if ($letters)
        <x-mail.aside :messages="$messages"/>
        <x-ui.action-bar>
            <x-ui.button form="request-form" class="min-w-0 flex-1">Завести</x-ui.button>
            <x-mail.aside-button :count="$messages->count()"/>
        </x-ui.action-bar>
    @else
        <x-ui.action-bar><x-ui.button form="request-form" class="min-w-0 flex-1">Завести</x-ui.button></x-ui.action-bar>
    @endif
    </div>
</x-ui.shell>
