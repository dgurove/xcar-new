{{-- Блок машины (`Cars\CarFields::BLOCKS`) — один на CRM и парковку (19.10.2026, владелец: «один и тот же модуль кода и
     стиля, потому что это одно»): те же подписи, сетка, вид текстом и поля. Машина — `carRecord()` дорожки, вендор —
     своей стороны (у продажи — продажи, у парковки — парковки); имя поля — имя дорожки (`CarFields::name`). Новая
     машина (заявка руками, разбор письма) — значения из `$values`. Что у продажи своего — суффиксы номера убытка
     (Мигторг, «б/н»), глазик VIN, «С НДС» по вендору. --}}
@props(['lane' => null, 'of' => null, 'block', 'values' => [], 'fold' => null, 'hints' => [], 'required' => [], 'as' => 'card', 'top' => null])
@php
    use App\Cars\CarFields;
    use App\Offers\{Offer, OfferState};
    use App\Support\{Liters, Money};
    $of ??= $lane ? $lane::class : null;
    $sale = $of === Offer::class;
    [$title, $columns] = CarFields::BLOCKS[$block];
    $car = $lane?->carRecord();
    $name = fn (string $c) => CarFields::name($of, $c);
    $raw = fn (string $c) => match (true) {
        $c === 'vendor_id' => $lane ? $lane->vendor_id : ($values['vendor_id'] ?? null),
        (bool) $car => $car->{$c},
        default => $values[$name($c)] ?? $values[$c] ?? null,
    };
    $vendor = $lane?->vendor ?? (($id = $values['vendor_id'] ?? null) ? \App\Vendors\Vendor::find($id) : null);
    $brand = $car?->brand ?? ($values['brand'] ?? null);
    $model = $car?->model ?? ($values['model'] ?? null);
    // Свёрнутый блок раскрывается сам, если в нём ошибка или расхождение (Мигторг, документы).
    $names = CarFields::names($of, $block);
    if ($fold && ($errors->hasAny($names) || array_intersect($hints, $names))) {
        $fold = false;
    }
    $text = function (string $c) use ($raw, $vendor, $brand, $model) {
        $v = $raw($c);
        return match (CarFields::kind($c)) {
            'vendor' => $vendor?->name,
            'brand' => $brand?->name,
            'model' => $model?->name,
            'enum' => $v instanceof \BackedEnum ? $v->label() : ($v ? CarFields::enum($c)::tryFrom($v)?->label() : null),
            'digits' => $v !== null && $v !== '' ? Money::nums((int) $v).' км' : null,
            'liters' => $v ? Liters::format((int) $v).' л' : null,
            default => $c === 'engine_power' && $v ? $v.' л. с.' : ($v === '' ? null : $v),
        };
    };
    $cells = collect($columns)->mapWithKeys(fn ($c) => [$c => $text($c)])->filter(fn ($v) => $v !== null && $v !== '');
    // ПТС с пятнадцатью цифрами — электронный.
    $ptsLabel = fn ($v) => preg_match('/^\d{15}$/', preg_replace('/\s+/', '', (string) $v)) ? 'ЭПТС' : 'ПТС';
    $grid = $as === 'card' ? 'grid grid-cols-2 gap-3 @4xl:grid-cols-3' : 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-2.5';
    $req = fn (string $c) => in_array($c, $required, true);
    $draft = $sale && $lane?->state === OfferState::Draft;
@endphp
<x-car.card :title="$title" :fold="$fold" :empty="$cells->isEmpty()" :as="$as" :top="$top" {{ $attributes }}>
    <x-slot:view>
        @if ($cells->isNotEmpty())
            <div class="{{ $grid }} !gap-y-2.5 leading-snug">
                @foreach ($cells as $c => $value)
                    <div @class(['min-w-0', 'col-span-2' => $c === 'vin'])>
                        <div class="field-label">{{ $c === 'pts' ? $ptsLabel($value) : CarFields::label($c, $lane) }}</div>
                        <div class="mt-0.5 break-words">
                            @if ($c === 'vin')<x-ui.vin-code :vin="$value" copy/>
                            @elseif (CarFields::kind($c) === 'code' || $c === 'plate')<x-ui.copy-code :value="$value"/>
                            @elseif (CarFields::kind($c) === 'tel')<a href="tel:{{ preg_replace('/[^\d+]/', '', $value) }}" class="text-accent-text">{{ $value }}</a>
                            @else{{ $value }}@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-slot:view>
    <div class="{{ $grid }}">
        @foreach ($columns as $c)
            @switch (CarFields::kind($c))
                @case ('vendor')
                    @if ($sale)
                        {{-- У опубликованного вендор ведёт маршрут и волны показа: модератор меняет его только в черновике.
                             Сменили вендора — «С НДС» у закупочной встаёт по нему (`vendor_vat_controller`). --}}
                        <div class="contents" data-controller="vendor-vat" data-vendor-vat-ids-value="{{ json_encode(once(fn () => \App\Vendors\Vendor::where('offers_include_vat', true)->pluck('id')->all())) }}" data-action="change->vendor-vat#sync">
                            <x-ui.combobox name="vendor_id" label="Вендор" url="/reference/vendors" :create="auth()->user()->canManageCrm() ? '/reference/vendors' : null"
                                :value="$raw('vendor_id')" :text="$vendor?->name" :required="$req('vendor_id')"
                                :disabled="! auth()->user()->canManageCrm() && ! $draft"/>
                        </div>
                    @else
                        <x-ui.combobox name="vendor_id" label="Вендор" url="/reference/vendors" :value="$raw('vendor_id')" :text="$vendor?->name" :required="$req('vendor_id')"/>
                    @endif
                    @break
                @case ('vin')
                    <x-ui.vin :value="$raw('vin')" span="col-span-2 @4xl:col-span-1" :required="$req('vin')">
                        @if ($sale)
                            <x-slot:after-label><x-ui.eye-check name="show_vin" :checked="$lane->show_vin"/></x-slot:after-label>
                        @endif
                    </x-ui.vin>
                    {{-- Такая ТС уже есть — строками под полями сразу при вводе (`twins_controller` у `x-car.blocks`). --}}
                    <div class="col-span-full flex flex-col gap-2 empty:hidden" data-twins-target="box"></div>
                    @break
                @case ('brand')
                    <x-cars.brand-model :brand-id="$raw('brand_id')" :brand-text="$brand?->name" :model-id="$raw('model_id')" :model-text="$model?->name" :required="$req('brand_id')"/>
                    @break
                @case ('model')
                    @break
                @case ('enum')
                    <x-ui.field :name="$name($c)" :label="CarFields::label($c)" :options="CarFields::enum($c)::options()" placeholder="—" :value="$raw($c) instanceof \BackedEnum ? $raw($c)->value : $raw($c)"/>
                    @break
                @case ('liters')
                    <x-ui.field :name="$c" :label="CarFields::label($c)" placeholder="1,6" :value="Liters::format($raw($c))"/>
                    @break
                @case ('tel')
                    <x-ui.field :name="$name($c)" :id="'v-'.$name($c)" :label="CarFields::label($c)" type="tel" :value="$raw($c)"/>
                    @break
                @default
                    @if ($c === 'claim_ref')
                        <x-ui.field :name="$name($c)" :label="CarFields::label($c, $lane)" :value="$raw($c)" :required="$req($c)" copy>
                            @if ($sale && $lane?->exists)
                                {{-- Мигторг ищет по вписанному номеру; у вендора, у которого бывает без номера (ТИТ), — «б/н». --}}
                                <x-slot:suffix>@if ($vendor?->claim_ref_optional && ! $raw($c))<button type="button" class="claim-none" data-controller="put" data-action="put#set" data-put-value-param="б/н">б/н</button>@else<x-offer.migtorg-field :offer="$lane"/>@endif</x-slot:suffix>
                            @endif
                        </x-ui.field>
                    @else
                        <x-ui.field :name="$name($c)" :label="CarFields::label($c)" :value="$raw($c)" :digits="CarFields::kind($c) === 'digits'" :copy="CarFields::kind($c) === 'code'" :required="$req($c)"/>
                    @endif
            @endswitch
        @endforeach
    </div>
</x-car.card>
