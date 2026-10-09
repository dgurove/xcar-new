{{-- Оплаты (раздел CRM) — три вкладки по трём вопросам начальника (07.10.2026, владелец: «начальник не догадается вниз
     пролистать»): «По ссылкам» (все ссылки на оплату одним списком — сделок, гаража и взятые вручную, 09.10.2026; чипы
     «Откуда» и «Отменённые», `MoneyLinks`), «Нам должны» (менеджеры и их покупатели по сделкам и гаражу),
     «Мы должны» (вознаграждения менеджерам). Вид — таблицей (`admin/money/tab-table`, по умолчанию) или строками
     (`admin/money/tab`), внутри группы «Проверить», «Ждём», «Оплачено». Число у пилюли — сколько открытого, оранжевая —
     есть дело. Чип менеджера — на двух последних, один выбран — его расчёт строкой сверху;
     лупа — по всем трём вкладкам сразу. «Создать ссылку» — только админу. Выписки СберБизнеса здесь нет —
     Настройки → Банк. --}}
@php
    use App\Http\Admin\MoneyController; use App\Support\ListView;
    // Таблица по умолчанию (07.10.2026, владелец: «гораздо удобнее табличные»), строки — пунктом меню вида.
    $view = ListView::fromRequest(request()) === ListView::LIST ? ListView::LIST : ListView::TABLE;
@endphp
<x-ui.shell title="Оплаты" :heading="false" :detail="$detail">
    <x-admin.work-titles current="money"/>
    <x-ui.toolbar class="mt-5 max-sm:mt-3" name="money" :sort="$sort" :pills="MoneyController::TABS" :pill="$tab" pill-param="tab" :counts="$counts['counts']" :tones="$counts['tones']"
        :facets="$facets" search="Имя, ТС, номер" search-target="#money-feed">
        <x-slot:extra><x-ui.view-switch :views="[ListView::TABLE, ListView::LIST]" :current="$view"/></x-slot:extra>
        @if (auth()->user()->isAdmin() && app(\App\Billing\Acquiring\Gateway::class)->configured())
            <x-slot:actions>
                @include('admin.money.service-sheet')
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <div id="money-feed">
        @if ($manager)
            <div class="list mt-4"><x-money.manager-row :user="$manager['user']" :position="$manager['position']"/></div>
        @endif
        @if (collect($sections)->every(fn ($s) => isset($s['links']) ? collect($s['links'] instanceof \Illuminate\Support\Collection ? $s['links'] : $s['links']->items())->isEmpty() : $s['claims']->isEmpty() && $s['open']->isEmpty() && $s['paid']->isEmpty()))
            <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : match ($tab) { 'links' => 'Ссылок на оплату ещё не брали', 'owed' => 'Менеджеры ничего не должны', default => 'Выплачивать нечего' } }}</x-ui.empty>
        @endif
        @foreach ($sections as $key => $section)
            @if ($key === 'links')
                @include($view === ListView::TABLE ? 'admin.money.links-table' : 'admin.money.links', ['links' => $section['links'], 'titled' => count($sections) > 1])
            @else
                @include($view === ListView::TABLE ? 'admin.money.tab-table' : 'admin.money.tab', ['key' => $key, 's' => $section, 'titled' => count($sections) > 1])
            @endif
        @endforeach
    </div>
</x-ui.shell>
