{{-- Оплаты (раздел CRM) — три раздела крупными заголовками (`x-ui.big-tabs`, 17.11.2026, владелец: «как было в „Работе“»):
     «Комиссии» (подбор ПРАЙМ и разовые услуги по ссылке) и «ДКП» (счета за машину от ПРАЙМ и Р.О.С.) — группы «Проверить»,
     «Просрочено», «Ждём» с суммой, чип «Откуда»; «Выплаты» (вознаграждения и перевозки — группой на получателя). Оплаченное — чипом «Оплаченные», в ленте
     только дела. Вид — таблицей (`admin/money/tab-table`, по умолчанию) или строками (`admin/money/tab`). Число у пилюли —
     сколько открытого, красная точка — есть дело. Чип менеджера — на обеих, один выбран — его расчёт строкой сверху; лупа —
     по обеим вкладкам сразу. «Создать ссылку» — только админу. Выписки СберБизнеса здесь нет — Настройки → Банк. --}}
@php
    use App\Http\Admin\MoneyController; use App\Support\ListView;
    // Таблица по умолчанию (07.10.2026, владелец: «гораздо удобнее табличные»), строки — пунктом меню вида.
    $view = ListView::fromRequest(request()) === ListView::LIST ? ListView::LIST : ListView::TABLE;
@endphp
<x-ui.shell title="Оплаты" :heading="false" :detail="$detail">
    <div class="title-row"><x-ui.big-tabs label="Оплаты" :tabs="MoneyController::TABS" :current="$tab" :href="fn ($k) => '/work/money?tab='.$k"
        :badges="$counts['counts']" :todo="$counts['tones']"/></div>
    <x-ui.toolbar name="money" :sort="$sort" :facets="$facets" search="Имя, ТС, номер" search-target="#money-feed">
        {{-- На телефоне обе раскладки — строки: переключателя нет, шапка в два ряда (09.10.2026). --}}
        <x-slot:extra><span class="contents max-sm:hidden"><x-ui.view-switch :views="[ListView::TABLE, ListView::LIST]" :current="$view"/></span></x-slot:extra>
        @if (auth()->user()->isAdmin() && app(\App\Billing\Acquiring\Gateway::class)->configured())
            <x-slot:actions>
                @include('admin.money.service-sheet')
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <div id="money-feed" data-detail-shape="plain">
        @if ($manager)
            <div class="list mt-4"><x-money.manager-row :user="$manager['user']" :position="$manager['position']"/></div>
        @endif
        @if (collect($sections)->every(fn ($s) => $s['claims']->isEmpty() && $s['open']->isEmpty() && $s['paid']->isEmpty()))
            <x-ui.empty class="mt-6">{{ match (true) { $q !== '' => 'Ничего не нашлось', $facets->on('paid') => $tab !== 'payouts' ? 'Оплаченных счетов нет' : 'Выплат ещё не было', $tab === 'fees' => 'Комиссий к оплате нет', $tab === 'dkp' => 'Счетов за ТС к оплате нет', default => 'Выплачивать нечего' } }}</x-ui.empty>
        @endif
        @foreach ($sections as $key => $section)
            @include($view === ListView::TABLE ? 'admin.money.tab-table' : 'admin.money.tab', ['key' => $key, 's' => $section, 'titled' => count($sections) > 1])
        @endforeach
    </div>
</x-ui.shell>
