{{-- Logistics XCar — вывоз (17.10.2026): задачи таблицей в два этажа — машина («!» — один ключ), под ней что дальше цветом
     и кто вывозит; от 640 — город, от 1024 — исполнитель. Нажатие — карточка рядом (ПК — колонкой, телефон — листом
     снизу). Админу первой пилюлей — «Без исполнителя», логисту — «У логиста». --}}
<x-ui.shell title="Вывоз" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :pills="$pills" :pill="$preset" pill-param="preset" :pill-home="$home" :counts="$counts" name="transports" :sort="$sort" search="Марка, VIN, владелец, трек" search-target="#transports"/>
    <div id="transports" class="mt-4">
        @if ($transports->isEmpty())
            <x-ui.empty class="mt-6">{{ match ($preset) { 'new' => 'Всем вывозам выбран исполнитель', 'logist' => 'Логист всех назначил', 'work', 'moving' => 'Вывозов в работе нет', default => 'Вывозов нет' } }}</x-ui.empty>
        @else
            <x-ui.table id="transports-table" :titles="$transports->map->title()">
                <x-slot:head>
                    <tr>
                        <x-ui.th :sort="$sort" key="title" class="grow">Марка, модель</x-ui.th>
                        <th class="hidden sm:table-cell">Город</th>
                        <th class="hidden lg:table-cell">Кто вывозит</th>
                        <x-ui.th :sort="$sort" key="when" class="num">Когда</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach ($transports as $t)<x-logistics.row :t="$t"/>@endforeach
            </x-ui.table>
        @endif
    </div>
</x-ui.shell>
