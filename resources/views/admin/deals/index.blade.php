@php
    $view = \App\Support\ListView::pick(request(), $deals->total());
    // Столбцы — одно описание на шапку, строки и заголовки групп (`DealController::table`).
    // Столбец кнопок — только когда в списке есть хоть одна кнопка дела (18.11.2026, владелец про «Ждём ответа»: «убери пустое
    // место, кнопки там не нужны»).
    $actions = $groups !== null && collect($groups)->contains(fn ($g) => collect($g['rows'] ?? [])->contains(fn ($r) => ! empty($r['todo']['button'])));
    $table = \App\Http\Admin\DealController::table($groups !== null, $actions, ($preset ?? null) === 'extra', in_array($preset ?? null, ['paying', 'paying_fee'], true) && $debts !== null);
@endphp
{{-- Вкладки сделки в «Предложениях» (16.11.2026: сделка — состояние предложения, `OfferController::DEAL_TABS`): то же колесо
     с разделами, что у предложений; вкладка — этап маршрута (`DealTabs`). Внутри — сначала наши дела зелёными группами, потом
     кого ждём (владелец: «сначала наше дело, потом кого ждём»). Ключ строки и окна — номер машины. --}}
<x-ui.shell :title="$presets[$preset] ?? 'Предложения'" :heading="false" :detail="$detail">
    {{-- Шапка — одна на вкладки колеса (`x-admin.tab-head`, 17.11.2026). --}}
    <x-admin.tab-head :presets="$presets" :preset="$preset" :counts="$counts" :sections="$sections" :sort="$sort" name="deals" :facets="$facets" search="Номер, марка, VIN, менеджер"
        :views="['list', 'table', 'wide']" :view="$view"/>

    <div id="list">
    @if ($groups !== null)
        {{-- Итог «Должны нам / просрочено» сверху снят (24.11.2026, владелец: «убери из шапки»): сумма — у каждой группы. --}}
        @if ($groups->isEmpty() && $facets->activeCount())
            <x-ui.empty class="mt-6" :href="$facets->resetUrl()" link="Сбросить фильтр">По фильтру ничего</x-ui.empty>
        @elseif ($groups->isEmpty())
            <x-ui.empty class="mt-6">Сделок на этом этапе нет</x-ui.empty>
        @else
            <x-ui.table :table="$table" id="deals" class="mt-6" view="table">
                <x-slot:head><x-table.head :table="$table"/></x-slot:head>
                @foreach ($groups as $key => $group)
                    <x-table.group :table="$table" :key="$key" :title="$group['title']" :count="$group['size']" :ours="$group['ours']" :danger="$group['danger'] ?? false" :sum="$group['sum'] ?? null"/>
                    @foreach ($group['rows'] as $row)<x-deal.todo-row :deal="$row['deal']" :todo="$row['todo']" :table="$table" :debt="$row['debt'] ?? null"/>@endforeach
                @endforeach
            </x-ui.table>
        @endif
    @elseif ($deals->isEmpty() && $facets->activeCount())
        <x-ui.empty class="mt-6" :href="$facets->resetUrl()" link="Сбросить фильтр">По фильтру ничего</x-ui.empty>
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
