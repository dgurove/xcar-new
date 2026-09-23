{{-- Сам список «Наличия»: таблица с окошком или карточки. Этим же куском отвечает живой поиск
     (заголовок X-List) — он подменяет содержимое #cars целиком. --}}
@php use App\Support\ListView; @endphp
@if ($vehicles->isEmpty())
    <x-ui.empty class="py-6">{{ $q !== '' ? 'Ничего не нашлось' : 'ТС нет' }}</x-ui.empty>
@else
    @if ($view === ListView::TABLE)
        <x-ui.table id="vehicles" :open="$peek">
            <x-slot:head>
                <tr>
                    <th>№</th>
                    <th class="grow">Марка, модель</th>
                    <th class="hidden sm:table-cell">Парковка</th>
                    <th class="hidden sm:table-cell">Вендор</th>
                    <th class="num">Дней</th>
                    <th class="num">₽/сут</th>
                    <th class="num">Начислено</th>
                </tr>
            </x-slot:head>
            @foreach ($vehicles as $vehicle)<x-park.table-row :vehicle="$vehicle" :total="$totals[$vehicle->id] ?? null" :debt="$debts[$vehicle->id] ?? 0"/>@endforeach
        </x-ui.table>
    @else
        <div class="{{ ListView::containerClass($view) }}" data-controller="ticker">
            @foreach ($vehicles as $vehicle)<x-park.card :vehicle="$vehicle" :debt="$debts[$vehicle->id] ?? 0" :total="$totals[$vehicle->id] ?? null"/>@endforeach
        </div>
    @endif
    @if ($vehicles->hasPages())<div class="mt-6"><x-ui.pager :of="$vehicles" :sizes="ListView::perSizes($view)"/></div>@endif
@endif
