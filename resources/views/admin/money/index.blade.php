{{-- Оплаты (раздел CRM) — три раздела крупными заголовками (`x-ui.big-tabs`, 17.11.2026, владелец: «как было в „Работе“»); в разделе —
     люди строками (`x-money.manager-row`, 18.11.2026, начальник: «сгруппировать по менеджерам»), внутри — все его сделки
     (`admin/money/person`, 22.11.2026). Чип «Статус» — этап сделки, по умолчанию после «Беру в работу»; «Оплаченных» нет — история
     у человека лентой. Лупа — прежней таблицей по всем разделам (`admin/money/tab-table`, «Строками» — `admin/money/tab`). Число у
     пилюли — сколько ждём денег, красная точка — есть дело. «Создать ссылку» — только админу. --}}
@php
    use App\Http\Admin\MoneyController; use App\Support\ListView;
    // Таблица по умолчанию (07.10.2026, владелец: «гораздо удобнее табличные»), строки — пунктом меню вида.
    $view = ListView::fromRequest(request()) === ListView::LIST ? ListView::LIST : ListView::TABLE;
@endphp
<x-ui.shell title="Оплаты" :heading="false" :detail="$detail">
    <div @class(['title-row', 'title-row--app' => \App\Billing\PayView::here()])><x-ui.big-tabs label="Оплаты" :tabs="MoneyController::tabs()" :current="$tab" :href="fn ($k) => '/work/money?tab='.$k"
        :badges="$counts['counts']" :todo="$counts['tones']"/>@if (\App\Billing\PayView::here())<span class="ml-auto shrink-0"><x-ui.me-menu/></span>@endif</div>
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
        @if ($people !== null)
            {{-- По менеджерам (18.11.2026): аватар, имя, что требует нас, долг и «›» — внутри его машины. --}}
            @if ($people->isEmpty())
                <x-ui.empty class="mt-6">{{ match (true) { $facets->active() => 'По фильтру ничего', $tab === 'payouts' => 'Выплат нет', default => 'Сделок нет' } }}</x-ui.empty>
            @else
                <div class="list mt-4">
                    @foreach ($people as $person)<x-money.manager-row :person="$person" :tab="$tab"/>@endforeach
                </div>
            @endif
        @else
            @if (collect($sections)->every(fn ($s) => $s['claims']->isEmpty() && $s['open']->isEmpty() && $s['paid']->isEmpty()))
                <x-ui.empty class="mt-6">Ничего не нашлось</x-ui.empty>
            @endif
            @foreach ($sections as $key => $section)
                @include($view === ListView::TABLE ? 'admin.money.tab-table' : 'admin.money.tab', ['key' => $key, 's' => $section, 'titled' => count($sections) > 1])
            @endforeach
        @endif
    </div>
</x-ui.shell>
