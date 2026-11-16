@php
    $view = \App\Support\ListView::pick(request(), $deals->total());
    // Столбцы — одно описание на шапку, строки и заголовки групп (`DealController::table`).
    $table = \App\Http\Admin\DealController::table($groups !== null, $stages !== null, $facets->searching() ? null : $preset, $waiting !== null);
@endphp
{{-- Вкладки сделки в «Предложениях» (16.11.2026: сделка — состояние предложения, `OfferController::DEAL_TABS`): то же колесо
     с разделами, что у предложений; строки — сделки, ключ строки и окна — номер машины. --}}
<x-ui.shell :title="$presets[$preset] ?? 'Предложения'" :heading="false" :detail="$detail">
    <div class="title-row">
        <x-ui.tab-title :pills="$presets" :pill="$preset" pill-param="preset" pill-home="" :counts="$counts" :badges="['estimating' => 'estimating_given', 'priced' => 'priced_given', 'us' => 'us']" :bumps="['legacy' => 'legacy_drafts']" :sections="$sections" :drop="['todo', 'what', 'sub', 'turn']" fallback="Предложения" id="offers-tabs"/>
    </div>
    <x-ui.toolbar :sort="$sort" name="deals" :facets="$facets" search="Номер, марка, VIN, менеджер">
        <x-slot:extra><x-ui.view-switch :views="['list', 'table', 'wide']" :current="$view"/></x-slot:extra>
        {{-- Вкладки дел «Ваш ход» и чего ждём во «Ждём …» (09.11.2026, `DealController::todo|waiting`). --}}
        @if ($work)
            <x-slot:sub><x-deal.work-tabs :tabs="$work['tabs']" :current="$work['tab']"/></x-slot:sub>
        @endif
    </x-ui.toolbar>

    <div id="list">

    @if ($groups !== null)
        {{-- «Ваш ход» — список дел группами «что сделать» (`DealController::todo`), а не таблица сроков (27.10.2026); с 09.11.2026
             вкладками дел, подзаголовок — ссылка на одну свою группу и скрепка с её адресом. --}}
        @if ($groups->isEmpty())
            <x-ui.empty class="mt-6" :href="$work['sub']['clear'] ?? null" link="Показать все">{{ $work['sub'] ? 'Здесь ничего не осталось' : 'Ничего не ждёт нас' }}</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deals" class="mt-6" view="table">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @foreach ($groups as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['size']" :href="$work['sub'] ? null : $group['href'] ?? null" :link="$group['link'] ?? null">@if ($group['button'])<x-deal.todo-button :button="$group['button']" group/>@endif @if ($work['sub'])<a href="{{ $work['sub']['clear'] }}" class="btn btn-s btn-quiet max-sm:hidden" data-turbo-action="replace" data-turbo-prefetch="false">Показать все</a>@endif</x-table.group>
                    @foreach ($group['rows'] as $row)<x-deal.todo-row :deal="$row['deal']" :todo="$row['todo']" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($debts !== null)
        {{-- «Оплата» — кто сколько должен нам и когда (27.10.2026, `DealController::debts`): итог, группы по менеджеру. --}}
        @if ($debts['groups']->isEmpty())
            <x-ui.empty class="mt-6">На оплате сделок нет</x-ui.empty>
        @else
            <div class="mt-5 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span>Должны нам <span class="nums font-semibold">{{ \App\Support\Money::rub($debts['owed']) }}</span></span>
                @if ($debts['overdue'] > 0)<span class="text-danger">просрочено <span class="nums font-semibold">{{ \App\Support\Money::rub($debts['overdue']) }}</span></span>@endif
            </div>
            <x-ui.table :table="$table" id="deals" class="mt-4" view="table">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @foreach ($debts['groups'] as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['rows']->count()"><span class="ml-auto nums text-sm">@if ($group['overdue'] >= $group['owed'])<span class="text-danger">{{ \App\Support\Money::rub($group['owed']) }} просрочено</span>@else{{ \App\Support\Money::rub($group['owed']) }}@if ($group['overdue'] > 0)<span class="text-danger">, из них просрочено {{ \App\Support\Money::rub($group['overdue']) }}</span>@endif @endif</span></x-table.group>
                    @foreach ($group['rows'] as $row)<x-deal.pay-row :deal="$row['deal']" :debt="$row['debt']" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($waiting !== null)
        {{-- «Ждём менеджера» / «Ждём поставщика» — группами по тому, кого ждём (`DealController::waiting`, 04.11.2026): менеджер
             с аватаром или вендор с логотипом, сколько просрочено; в строке — что ждём и срок. --}}
        @if ($waiting->isEmpty())
            <x-ui.empty class="mt-6">{{ $work['tab'] !== 'all' ? 'Здесь никого не ждём' : (request()->query('turn') === 'manager' ? 'Менеджеров не ждём' : 'Поставщиков не ждём') }}</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deals" class="mt-6" view="table">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @foreach ($waiting as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['size']">
                        <x-slot:before>@if ($group['manager'])<x-ui.avatar :user="$group['manager']" :size="24" class="mr-2 align-middle"/>@elseif ($group['vendor'])<x-vendor.logo :vendor="$group['vendor']" class="mr-2"/>@endif</x-slot:before>
                        @if ($group['late'])<span class="text-sm font-normal text-danger">просрочено {{ $group['late'] }}</span>@endif
                    </x-table.group>
                    @foreach ($group['rows'] as $deal)<x-deal.wait-row :deal="$deal" :table="$table"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($stages !== null)
        {{-- Вкладка этапа — группами по шагу маршрута в его порядке (`DealController::steps`, 16.11.2026). --}}
        @if ($stages->isEmpty())
            <x-ui.empty class="mt-6">Сделок на этом этапе нет</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deals" class="mt-6" view="table">
                <x-slot:head><x-table.head :table="$table" :sort="$sort"/></x-slot:head>
                @foreach ($stages as $key => $stage)
                    <x-table.group :table="$table" :key="'step:'.$stage['title']" :title="$stage['title']" :count="$stage['rows']->count()"/>
                    @foreach ($stage['rows'] as $deal)<x-deal.table-row :deal="$deal" :table="$table" :group="$stage['title']"/>@endforeach
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
