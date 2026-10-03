@php $view = \App\Support\ListView::pick(request(), $deals->total()); @endphp
<x-ui.shell title="Сделки" :heading="false">
    <x-admin.work-titles current="deals" :count="$deals->total()"/>
    <x-ui.toolbar class="mt-5" :sorts="\App\Http\Admin\DealController::SORTS" :sort="$sort" :pills="\App\Http\Admin\DealController::PRESETS" :pill="$preset" pill-param="preset" name="deals" :facets="$facets" search="Номер, марка, VIN, менеджер">
        <x-slot:extra><x-ui.view-switch :views="['list', 'table', 'wide']" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>

    <div id="list">

    @if ($deals->isEmpty())
        <x-ui.empty class="mt-6">Сделок нет</x-ui.empty>
    @else
        @if (\App\Support\ListView::isTable($view))
            <x-ui.table id="deals" class="mt-6" :view="$view">
                <x-slot:head><x-deal.table-head/></x-slot:head>
                @foreach ($deals as $deal)<x-deal.table-row :deal="$deal"/>@endforeach
            </x-ui.table>
        @else
            <div class="list mt-6">
                @foreach ($deals as $deal)
                    @include('admin.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        @endif
        <div class="mt-8"><x-ui.pager :of="$deals"/></div>
    @endif
    </div>
</x-ui.shell>
