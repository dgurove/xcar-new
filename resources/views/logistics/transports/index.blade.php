{{-- Logistics XCar: перевозки таблицей в два этажа — машина, под ней ход перевозки цветным словом и кто везёт; от 640 —
     маршрут регионами, от 1024 — даты. Нажатие — карточка рядом (ПК — колонкой, телефон — листом снизу). Логисту первой
     пилюлей — «Ждут логиста»: межрегиональные без исполнителя. --}}
<x-ui.shell title="Перевозки" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :pills="$pills" :pill="$preset" pill-param="preset" :pill-home="$home" :counts="$counts" name="transports" search="Марка, VIN, адрес" search-target="#transports"/>
    <div id="transports" class="mt-4">
        @if ($transports->isEmpty())
            <x-ui.empty class="mt-6">{{ $preset === 'waiting' ? 'Все перевозки назначены' : 'Перевозок нет' }}</x-ui.empty>
        @else
            <x-ui.table id="transports-table" :titles="$transports->map->title()">
                <x-slot:head>
                    <tr>
                        <th class="grow">Машина</th>
                        <th class="hidden sm:table-cell">Маршрут</th>
                        <th class="hidden lg:table-cell">Кто везёт</th>
                        <th class="num">Когда</th>
                    </tr>
                </x-slot:head>
                @foreach ($transports as $t)<x-logistics.row :t="$t"/>@endforeach
            </x-ui.table>
        @endif
    </div>
</x-ui.shell>
