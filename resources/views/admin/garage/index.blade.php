{{-- «Работа → Гараж»: у кого что стоит и во что обошлось — таблицей CRM (как список iOS), группами по менеджеру
     (взятые под себя — последними). Нажатие по строке — карточка: путь машины, деньги, расходы и действия; «Открыть
     страницу» — редактор предложения с карточкой «Гараж». --}}
<x-ui.shell title="Гараж" :heading="false" :detail="$detail">
    <x-admin.work-titles current="garage" :count="$total"/>
    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\GarageController::PRESETS" :pill="$preset" pill-param="preset" name="garage" :facets="$facets"/>

    @if ($groups->isEmpty())
        <x-ui.empty class="mt-6">В гараже пусто</x-ui.empty>
    @else
        <x-ui.table id="garage-table" class="mt-4">
            <x-slot:head>
                <tr><th class="grow">Марка, модель</th><th class="cell-dim hidden sm:table-cell">Вендор, № убытка</th><th class="num hidden sm:table-cell col-detail-hide">Расходы</th><th class="num">Сумма</th></tr>
            </x-slot:head>
            @foreach ($groups as $cars)
                <tr class="table-group"><th colspan="4"><span class="table-group-name">{{ $cars->first()->manager?->name ?? 'Взяли под себя' }} <span class="nums">{{ $cars->count() }}</span></span></th></tr>
                @foreach ($cars as $car)<x-admin.garage-row :car="$car"/>@endforeach
            @endforeach
        </x-ui.table>
    @endif
</x-ui.shell>
