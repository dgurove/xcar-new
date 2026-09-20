@php $view = \App\Support\ListView::pick(request(), $vehicles->total()); @endphp
<x-ui.shell :title="$yard ? $yard->name : 'ТС'" :count="$vehicles->total()" :back="$yard ? ['Стоянки', '/yards'] : false" :phone-heading="(bool) $yard">
    <x-ui.toolbar :sorts="\App\Http\Park\VehicleController::SORTS" :sort="$sort" :pills="\App\Http\Park\VehicleController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :hidden="array_filter(['yard' => $yard ? request('yard') : null, 'vendor' => request('vendor'), 'docs' => request('docs'), \App\Support\ListView::PARAM => request(\App\Support\ListView::PARAM)])" name="vehicles">
        <x-slot:pillsExtra>
            @if ($docsDue)<x-ui.pill :href="request()->fullUrlWithQuery(['docs' => request('docs') === 'due' ? null : 'due', 'page' => null])" :current="request('docs') === 'due'">Бумаги вендору <span class="nums text-ink-dim">{{ $docsDue }}</span></x-ui.pill>@endif
        </x-slot:pillsExtra>
        <x-slot:extra><x-ui.view-switch :current="$view"/></x-slot:extra>
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" placeholder="Номер, VIN, госномер, марка" class="field-input" enterkeyhint="search">
            <select name="vendor" class="field-input"><option value="">Все вендоры</option>@foreach ($vendors as $id => $name)<option value="{{ $id }}" @selected((string) request('vendor') === (string) $id)>{{ $name }}</option>@endforeach</select>
            @unless ($yard)<select name="yard" class="field-input"><option value="">Все площадки</option>@foreach ($yards as $id => $name)<option value="{{ $id }}" @selected((string) request('yard') === (string) $id)>{{ $name }}</option>@endforeach</select>@endunless
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($vehicles->isEmpty())
        <x-ui.empty class="mt-6">ТС нет</x-ui.empty>
    @else
        @if ($view === \App\Support\ListView::TABLE)
            <x-ui.table id="vehicles" class="mt-6" :open="$peek">
                <x-slot:head>
                    <tr>
                        <th>№</th>
                        <th class="grow">Марка, модель</th>
                        <th>Состояние</th>
                        <th class="hidden sm:table-cell">Стоянка</th>
                        <th class="hidden sm:table-cell">Вендор</th>
                        <th class="num">Дней</th>
                    </tr>
                </x-slot:head>
                @foreach ($vehicles as $vehicle)<x-park.table-row :vehicle="$vehicle"/>@endforeach
            </x-ui.table>
        @else
        <div class="mt-6 {{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0"/>@endforeach
        </div>
        @endif
        <div class="mt-8"><x-ui.pager :of="$vehicles" :sizes="\App\Support\ListView::perSizes($view)"/></div>
    @endif
</x-ui.shell>
