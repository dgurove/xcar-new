{{-- Поля перевозки — одни в CRM («Вывоз» → «Логистика»), в деле ТС на парковке и у логиста (`SaveTransport`). Вопросы по
     шагам (владелец 10.10.2026): внутри региона или между регионами; внутри — партнёр региона со стоимостью или наш
     эвакуатор; между — наш водитель или перевозчик, стоимость и сроки (из CRM можно оставить логисту). Откуда, куда и
     кто принимает — свободным текстом. prefix — свои id полей у каждой формы; yards — парковки в точке B (с парковки). --}}
@props(['transport' => null, 'car' => null, 'prefix' => 'transport', 'yards' => null, 'logist' => false])
@php
    use App\Logistics\Choices;
    use App\Logistics\Executor;
    use App\Logistics\Scope;
    $t = $transport;
    $scope = old('scope', $t?->scope?->value ?? Scope::Local->value);
    $executor = old('executor', $t?->executor?->value ?? '');
    $from = old('from_region_id', $t?->from_region_id ?? Choices::regionOf($car));
    $regions = Choices::regions();
    $partners = Choices::partners();
    $drivers = Choices::drivers();
    $carriers = Choices::carriers();
    $cost = old('cost', $t?->cost !== null ? (float) $t->cost : null);
@endphp
<div {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    <div class="flex flex-wrap gap-2">
        @foreach (Scope::cases() as $s)
            <label class="choice"><input type="radio" name="scope" value="{{ $s->value }}" @checked($scope === $s->value) data-action="reveal#pick"><span>{{ $s->label() }}</span></label>
        @endforeach
    </div>
    @error('scope')<p class="field-error">{{ $message }}</p>@enderror
    <x-ui.field name="from_region_id" id="{{ $prefix }}-from-region" label="Регион" :options="$regions" placeholder="Выберите" :value="$from" required/>

    {{-- Внутри региона: партнёр (менеджер с этим регионом) за стоимость эвакуации или наш эвакуатор. --}}
    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="local" @if ($scope !== 'local') hidden @endif>
        <div class="flex flex-col gap-4" data-controller="reveal">
            <div class="flex flex-wrap gap-2">
                {{-- Кого нет (партнёров, водителей) — того и не выбрать: без подписей-пояснений. --}}
                @foreach (array_filter([$partners ? Executor::Partner : null, $drivers ? Executor::Driver : null]) as $e)
                    <label class="choice"><input type="radio" name="executor" value="{{ $e->value }}" @checked($scope === 'local' && $executor === $e->value) data-action="reveal#pick"><span>{{ $e->label() }}</span></label>
                @endforeach
            </div>
            <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="partner" @if ($executor !== 'partner') hidden @endif>
                <x-ui.field name="executor_id" id="{{ $prefix }}-partner" label="Партнёр" :options="$partners" placeholder="Выберите" :value="old('executor_id', $t?->executor === Executor::Partner ? $t->executor_id : null)"/>
                <x-ui.field name="cost" id="{{ $prefix }}-partner-cost" label="Стоимость эвакуации, ₽" :value="$cost"/>
            </div>
            <div data-reveal-target="pane" data-reveal-key="driver" @if ($scope !== 'local' || $executor !== 'driver') hidden @endif>
                <x-ui.field name="executor_id" id="{{ $prefix }}-local-driver" label="Водитель" :options="$drivers" placeholder="Выберите" :value="old('executor_id', $t?->executor === Executor::Driver ? $t->executor_id : null)"/>
            </div>
            @error('executor')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Между регионами: куда; кто везёт — логист (из CRM можно оставить ему), наш водитель или перевозчик. --}}
    <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="intercity" @if ($scope !== 'intercity') hidden @endif>
        <x-ui.field name="to_region_id" id="{{ $prefix }}-to-region" label="Куда, регион" :options="$regions" placeholder="Выберите" :value="old('to_region_id', $t?->scope === Scope::Intercity ? $t->to_region_id : null)" required/>
        <div class="flex flex-col gap-4" data-controller="reveal">
            <div class="flex flex-wrap gap-2">
                @unless ($logist)<label class="choice"><input type="radio" name="executor" value="" @checked($scope === 'intercity' && $executor === '') data-action="reveal#pick"><span>Назначит логист</span></label>@endunless
                @foreach (array_filter([$drivers ? Executor::Driver : null, Executor::Carrier]) as $e)
                    <label class="choice"><input type="radio" name="executor" value="{{ $e->value }}" @checked($scope === 'intercity' && $executor === $e->value) data-action="reveal#pick"><span>{{ $e === Executor::Driver ? 'Наш водитель' : $e->label() }}</span></label>
                @endforeach
            </div>
            <div data-reveal-target="pane" data-reveal-key="driver" @if ($scope !== 'intercity' || $executor !== 'driver') hidden @endif>
                <x-ui.field name="executor_id" id="{{ $prefix }}-far-driver" label="Водитель" :options="$drivers" placeholder="Выберите" :value="old('executor_id', $t?->executor === Executor::Driver ? $t->executor_id : null)"/>
            </div>
            <div class="flex flex-col gap-4" data-reveal-target="pane" data-reveal-key="carrier" @if ($executor !== 'carrier') hidden @endif>
                <x-ui.field name="carrier" id="{{ $prefix }}-carrier" label="Перевозчик" :value="old('carrier', $t?->carrier)" list="{{ $prefix }}-carriers" autocomplete="off"/>
                <datalist id="{{ $prefix }}-carriers">@foreach ($carriers as $c)<option value="{{ $c }}"></option>@endforeach</datalist>
                <x-ui.field name="cost" id="{{ $prefix }}-carrier-cost" label="Стоимость, ₽" :value="$cost"/>
            </div>
            @error('executor')<p class="field-error">{{ $message }}</p>@enderror
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="ship_on" id="{{ $prefix }}-ship" type="date" label="Отправка" :value="old('ship_on', $t?->ship_on?->toDateString())"/>
                <x-ui.field name="arrive_on" id="{{ $prefix }}-arrive" type="date" label="Прибытие" :value="old('arrive_on', $t?->arrive_on?->toDateString())"/>
            </div>
        </div>
    </div>

    <x-ui.field name="from_address" id="{{ $prefix }}-from" label="Откуда" :value="old('from_address', $t?->from_address ?? Choices::fromAddress($car))"/>
    <x-ui.field name="to_address" id="{{ $prefix }}-to" label="Куда" :value="old('to_address', $t?->to_address)"/>
    @if ($yards && $yards->isNotEmpty())
        <x-ui.field name="to_yard_id" id="{{ $prefix }}-yard" label="На парковку" :options="$yards->mapWithKeys(fn ($y) => [$y->id => $y->name])" placeholder="Не на парковку" :value="old('to_yard_id', $t?->to_yard_id)"/>
    @endif
    <x-ui.field name="receiver" id="{{ $prefix }}-receiver" label="Кто принимает" :value="old('receiver', $t?->receiver)" placeholder="Имя и телефон"/>
    @error('executor_id')<p class="field-error">{{ $message }}</p>@enderror
    @error('cost')<p class="field-error">{{ $message }}</p>@enderror
</div>
