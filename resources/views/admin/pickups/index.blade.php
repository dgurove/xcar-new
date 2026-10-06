{{-- «Работа → Вывоз»: кто что вывозит — таблицей CRM, группами по ответственному («Мы» — последней). Нажатие по строке —
     карточка: путь вывоза с кнопками, «Кто и куда вывозит»; «Открыть страницу» — редактор предложения. --}}
<x-ui.shell title="Вывоз" :heading="false" :detail="$detail">
    <x-admin.work-titles current="pickups" :count="$total"/>
    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Admin\PickupController::PRESETS" :pill="$preset" pill-param="preset" name="pickups" :facets="$facets"/>

    @if ($groups->isEmpty())
        <x-ui.empty class="mt-6">Вывозить нечего</x-ui.empty>
    @else
        <x-ui.table id="pickups-table" class="mt-4">
            <x-slot:head>
                <tr><th class="grow">Марка, модель</th><th class="cell-dim hidden sm:table-cell">Вендор, № убытка</th><th class="num">Куда</th></tr>
            </x-slot:head>
            @foreach ($groups as $offers)
                <tr class="table-group"><th colspan="3"><span class="table-group-name">{{ $offers->first()->evacuator?->name ?? 'Мы' }} <span class="nums">{{ $offers->count() }}</span></span></th></tr>
                @foreach ($offers as $offer)<x-admin.pickup-row :offer="$offer"/>@endforeach
            @endforeach
        </x-ui.table>
    @endif
</x-ui.shell>
