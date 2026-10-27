@php
    $view = \App\Support\ListView::pick(request(), $deals->total());
    // Столбцы — одно описание на шапку, строки и заголовки групп (`DealController::table`).
    $table = \App\Http\Admin\DealController::table($groups !== null, $stages !== null, $facets->searching() ? null : $preset);
@endphp
<x-ui.shell title="Сделки" :heading="false" :detail="$detail">
    <x-admin.work-titles current="deals" :count="$deals->total()"/>
    <x-ui.toolbar class="mt-5" :sort="$sort" :pills="\App\Http\Admin\DealController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" name="deals" :facets="$facets" search="Номер, марка, VIN, менеджер">
        <x-slot:extra><x-ui.view-switch :views="['list', 'table', 'wide']" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>

    <div id="list">

    @if ($groups !== null)
        {{-- «Ваш ход» — список дел группами «что сделать» (`DealController::todo`), а не таблица сроков (27.10.2026). --}}
        @if ($groups->isEmpty())
            <x-ui.empty class="mt-6">Ничего не ждёт нас</x-ui.empty>
        @else
            <x-ui.table id="deals" class="mt-6" view="table" :titles="$groups->flatMap(fn ($g) => $g['rows'])->map(fn ($r) => $r['deal']->offer?->titleWithYear())" :rest="50">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @foreach ($groups as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['rows']->count()">@if ($group['button'])<x-deal.todo-button :button="$group['button']" group/>@endif</x-table.group>
                    @foreach ($group['rows'] as $row)<x-deal.todo-row :deal="$row['deal']" :todo="$row['todo']" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($deals->isEmpty())
        <x-ui.empty class="mt-6">Сделок нет</x-ui.empty>
    @else
        {{-- Кто сколько должен (27.10.2026, `DealDebt::totals`): во «Все» и с чипом «Оплата»; «просрочено» включает чип. --}}
        @if ($debt && $debt['owed'] > 0)
            <div class="mt-5 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span>Должны нам <span class="nums font-semibold">{{ \App\Support\Money::rub($debt['owed']) }}</span></span>
                @if ($debt['overdue'] > 0)
                    <a href="{{ $facets->url(['pay' => 'late']) }}" class="text-danger" data-turbo-action="replace" data-turbo-prefetch="false">просрочено <span class="nums font-semibold">{{ \App\Support\Money::rub($debt['overdue']) }}</span></a>
                @endif
            </div>
        @endif
        @if ($stages !== null)
            {{-- «Все» — воронка: группы по этапу в порядке маршрута (`DealController::stages`). --}}
            <x-ui.table id="deals" class="mt-6" :view="$view" :titles="$deals->getCollection()->map(fn ($d) => $d->offer?->titleWithYear())" :rest="66">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @foreach ($stages as $key => $stage)
                    <x-table.group :table="$table" :key="'stage:'.$stage['title']" :title="$stage['title']" :count="$stage['rows']->count()"/>
                    @foreach ($stage['rows'] as $deal)<x-deal.table-row :deal="$deal" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @elseif (\App\Support\ListView::isTable($view))
            <x-ui.table id="deals" class="mt-6" :view="$view" :titles="$deals->getCollection()->map(fn ($d) => $d->offer?->titleWithYear())" :rest="66">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @foreach ($deals as $deal)<x-deal.table-row :deal="$deal" :table="$table"/>@endforeach
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
    {{-- Окно писем для строк «Письма» в карточке сделки и письма шага. --}}
    <x-mail.window/>
</x-ui.shell>
