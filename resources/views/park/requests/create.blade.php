{{-- Новая заявка одной страницей. С кандидатом из письма поля предзаполнены, письма — окном («Письма N» в плашке),
     кадры из письма — лентой под полями, чтобы сверять, не уходя со страницы. Тип «приём» / «эвакуация» решает поле «Доставка». --}}
@php use App\Park\{RequestType, Delivery}; $p = $p ?? []; $val = fn ($k) => old($k, $p[$k] ?? null); $letters = $candidate !== null; $delivery = $letters ? $val('delivery') : old('delivery', Delivery::Self->value); $card = $candidate?->card(); $mailPhotos = $candidate?->photosCount() ?? 0; @endphp
<x-ui.shell title="Новая заявка">
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="flex min-w-0 flex-col gap-4">
    @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
    <form method="post" action="/requests" id="request-form" data-controller="vin draft" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">
        @if ($candidate)
            <input type="hidden" name="candidate_id" value="{{ $candidate->id }}">
            @foreach ((array) ($p['flags'] ?? []) as $f)<input type="hidden" name="flags[]" value="{{ $f }}">@endforeach
            @foreach ((array) ($p['docs_required'] ?? []) as $d)<input type="hidden" name="docs_required[]" value="{{ $d }}">@endforeach
        @endif
        @if ($vehicle)
            {{-- ТС уже есть (завели руками или заявка к стоящей): письма и заявка привяжутся к ней. --}}
            <x-park.vehicle-row :vehicle="$vehicle" :href="'/cars/'.$vehicle->id">
                <x-park.state :vehicle="$vehicle"/>
                @if ($candidate)<span class="chip">Письма привяжутся</span>@endif
            </x-park.vehicle-row>
            <input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
        @elseif (in_array($type, [RequestType::Intake, RequestType::Tow], true))
            <x-ui.card title="Транспортное средство">
                <x-park.vehicle-fields :values="$p" :brand="$p['brand'] ?? null" :model="$p['model'] ?? null" :vendors="$vendors" :categories="$categories" cols="grid-cols-2 sm:grid-cols-3"/>
            </x-ui.card>
        @else
            <x-ui.card title="Транспортное средство">
                <x-ui.combobox name="vehicle_id" label="Номер убытка, VIN или госномер" url="/reference/cars"/>
            </x-ui.card>
        @endif
        @if ($p['stages'] ?? null)
            @php $st = $p['stages']; $sv = fn ($k) => old('stages.'.$k, $st[$k] ?? null); @endphp
            {{-- По письмам ТС уже принята (и, может быть, продана): этапы менеджеру на проверку, заведётся стоящей. --}}
            <x-ui.card title="По письмам">
                <div class="flex flex-col gap-4" data-controller="spots" data-spots-map-value="{{ json_encode($yardRows) }}">
                    <div class="flex flex-col gap-3">
                        <x-ui.check name="stages[stored]" :checked="(bool) $sv('stored')">{{ $st['stored_title'] ?? 'Принята' }}</x-ui.check>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <x-ui.field name="stages[accepted_at]" label="Когда" type="date" :value="$sv('accepted_at')"/>
                            <x-ui.field name="stages[yard_id]" label="Парковка" :options="$yards" :value="$sv('yard_id') ?? $yards->keys()->first()" data-spots-target="yard" data-action="change->spots#sync"/>
                            <x-ui.field name="stages[spot]" label="Место" list="spots-list" autocapitalize="characters" :value="$sv('spot')"/>
                            <datalist id="spots-list" data-spots-target="list"></datalist>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3">
                        <x-ui.check name="stages[sold]" :checked="(bool) $sv('sold')">Продана, покупатель заберёт</x-ui.check>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <x-ui.field name="stages[sold_at]" label="Когда" type="date" :value="$sv('sold_at')"/>
                            <x-ui.field name="stages[pickup_name]" label="Кому выдать" :value="$sv('pickup_name')"/>
                            <x-ui.field name="stages[pickup_phone]" label="Телефон" type="tel" :value="$sv('pickup_phone')"/>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        @else
        <x-ui.card title="Заявка">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @if ($type === RequestType::Intake && $letters)
                    {{-- Из письма — только подсказка: как привезут, решает звонок страхователю. --}}
                    @if ($val('delivery_hint'))<div class="col-span-full"><span class="chip">В письме: {{ $val('delivery_hint') }}</span></div>@endif
                @elseif ($type === RequestType::Intake)
                    {{-- Эвакуатор или сам — узнаём по телефону; «ещё не знаем» оставляет заявку в «Связаться». Руками заводят, когда уже знают — по умолчанию «сам». --}}
                    <div class="field col-span-full">
                        <span class="field-label">Доставка</span>
                        <div class="flex flex-wrap gap-1.5">
                            <label class="choice"><input type="radio" name="delivery" value="" @checked(!$delivery)><span>Ещё не знаем</span></label>
                            @foreach (Delivery::cases() as $d)
                                <label class="choice"><input type="radio" name="delivery" value="{{ $d->value }}" @checked($delivery === $d->value)><span>{{ $d->label() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($type === RequestType::Move)
                    <x-ui.field name="yard_id" label="Куда" :options="$yards" placeholder="—"/>
                @endif
                <x-ui.field name="planned_at" label="Когда" type="datetime-local" :value="$val('planned_at')"/>
                @if ($type === RequestType::Tow || $letters)
                    <x-ui.field name="from_address" label="Откуда" :value="$val('from_address') ?? $vehicle?->offer?->inspection_address" span="col-span-2"/>
                @endif
                @if ($vehicle || !in_array($type, [RequestType::Intake, RequestType::Tow], true))
                    <x-ui.field name="contact_name" :label="$type === RequestType::Tow ? 'Страхователь' : 'Кто сдаёт'" :value="$val('contact_name') ?? $vehicle?->contact_name"/>
                    <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$val('contact_phone') ?? $vehicle?->contact_phone"/>
                @endif
                <x-ui.field name="note" label="Заметка" type="textarea" span="col-span-full" :value="$val('note')"/>
            </div>
        </x-ui.card>
        @endif
        @if ($card || $mailPhotos)
            {{-- Из письма — один кадр; все фото лежат в письме, откроет окно писем. --}}
            <x-ui.card title="Из письма">
                <div class="flex items-start gap-3">
                    @if ($card)<img src="{{ \App\Media\MediaUrl::for($card) }}" alt="" class="w-40 shrink-0 rounded-(--radius-m) object-cover">@endif
                    @if ($mailPhotos)<x-mail.window-button chip :url="'/requests/from-mail/'.$candidate->id.'/letters'" :label="$mailPhotos.' фото в письме'"/>@endif
                </div>
            </x-ui.card>
        @endif
    </form>
    </div>
    </div>
    @if ($letters)<x-mail.window/>@endif
    <x-ui.action-bar>
        <x-ui.button form="request-form" class="min-w-0 flex-1">Завести</x-ui.button>
        @if ($letters)
            <x-mail.window-button :count="$messages->count()" :url="'/requests/from-mail/'.$candidate->id.'/letters'" class="shrink-0" label="" aria-label="Письма"/>
            <form method="post" action="/requests/from-mail/{{ $candidate->id }}/decline" class="contents">@csrf<button class="btn btn-ghost shrink-0" data-turbo-confirm="В архив? Письмо уйдёт из «Ждут»">В архив</button></form>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
