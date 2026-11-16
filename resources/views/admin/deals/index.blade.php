@php
    $view = \App\Support\ListView::pick(request(), $deals->total());
    // Столбцы — одно описание на шапку, строки и заголовки групп (`DealController::table`).
    $table = \App\Http\Admin\DealController::table($groups !== null);
@endphp
{{-- Вкладки сделки в «Предложениях» (16.11.2026: сделка — состояние предложения, `OfferController::DEAL_TABS`): то же колесо
     с разделами, что у предложений; вкладка — этап маршрута (`DealTabs`). Внутри — сначала наши дела зелёными группами, потом
     кого ждём (владелец: «сначала наше дело, потом кого ждём»). Ключ строки и окна — номер машины. --}}
<x-ui.shell :title="$presets[$preset] ?? 'Предложения'" :heading="false" :detail="$detail">
    <div class="title-row">
        <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="\App\Http\Admin\OfferController::badges()" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :drop="['todo', 'what', 'sub', 'turn']" fallback="Предложения" id="offers-tabs"/>
    </div>
    <x-ui.toolbar :sort="$sort" name="deals" :facets="$facets" search="Номер, марка, VIN, менеджер">
        <x-slot:extra><x-ui.view-switch :views="['list', 'table', 'wide']" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>

    <div id="list">
    @if ($groups !== null)
        @if ($debts && $debts['owed'] > 0)
            {{-- «Менеджер должен нам» — кто сколько должен нам (27.10.2026, `DealDebt`). --}}
            <div class="mt-5 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span>Должны нам <span class="nums font-semibold">{{ \App\Support\Money::rub($debts['owed']) }}</span></span>
                @if ($debts['overdue'] > 0)<span class="text-danger">просрочено <span class="nums font-semibold">{{ \App\Support\Money::rub($debts['overdue']) }}</span></span>@endif
            </div>
        @endif
        @if ($groups->isEmpty())
            <x-ui.empty class="mt-6">Сделок на этом этапе нет</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deals" class="mt-6" view="table">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @foreach ($groups as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['size']" :ours="$group['ours']"/>
                    @foreach ($group['rows'] as $row)<x-deal.todo-row :deal="$row['deal']" :todo="$row['todo']" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($deals->isEmpty())
        <x-ui.empty class="mt-6">Сделок нет</x-ui.empty>
    @else
        @if (\App\Support\ListView::isTable($view))
            <x-ui.table :table="$table" id="deals" class="mt-6" :view="$view" :portion="$deals">
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
