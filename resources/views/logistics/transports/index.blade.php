{{-- Logistics XCar — вывоз: одна таблица (18.10.2026, владелец: «полноценная, удобная»). Столбцы — по человеку и вкладке
     (`TransportController::columns`): ведущим логистику (админ, модераторы) — вендор с номером убытка, продажа, кому
     везти, когда поступила; по умолчанию новые сверху, сортировка каждым заголовком. Нажатие — карточка рядом (ПК —
     колонкой, телефон — листом снизу). На «Без исполнителя» у ведущих галочки: «Назначить (N)» логисту или нашим, «Уже
     вывезли» задним числом и «Отменить» с причиной (число — у главной, три с числом на телефоне не влезали). «Готово» и «Все» — страницами. --}}
<x-ui.shell title="Вывоз" :phone-heading="false" :detail="$detail">
    <x-ui.toolbar :pills="$pills" :pill="$preset" pill-param="preset" :pill-home="$home" :counts="$counts" name="transports" :sort="$sort" :facets="$facets" search="{{ auth()->user()->leadsLogistics() ? 'Марка, VIN, убыток, владелец, трек' : 'Марка, VIN, владелец, трек' }}" search-target="#transports">
        @if (auth()->user()->isLogist())
            {{-- Своя заявка логиста — от его заказчика (18.10.2026). --}}
            <x-slot:actions>
                <a href="/transports/new" class="btn btn-s btn-accent btn-round shrink-0" aria-label="Новая заявка"><x-ui.icon name="plus" class="size-5"/></a>
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <x-reach.card title="Не пропускайте вывозы"/>
    <div id="transports" class="mt-4" @if ($pick) data-controller="pick" @endif>
        @if ($transports->isEmpty())
            <x-ui.empty class="mt-6">{{ match ($preset) { 'new' => 'Всем вывозам выбран исполнитель', 'logist' => 'Логист всех назначил', 'work', 'moving' => 'Вывозов в работе нет', default => 'Вывозов нет' } }}</x-ui.empty>
        @else
            @if ($pick)
                <div class="pick-all"><label class="row-check gap-3"><span class="check"><input type="checkbox" id="pick-all-transports" data-turbo-permanent data-pick-target="all" data-action="pick#pickAll"></span><span>Выбрать все</span></label></div>
            @endif
            <x-ui.table id="transports-table" :titles="collect($transports instanceof \Illuminate\Contracts\Pagination\Paginator ? $transports->items() : $transports)->map->title()">
                <x-slot:head><x-logistics.head :cols="$cols" :sort="$sort"/></x-slot:head>
                @foreach ($transports as $t)<x-logistics.row :t="$t" :checkable="$pick" :cols="$cols" :preset="$preset"/>@endforeach
            </x-ui.table>
            @if ($transports instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<x-ui.pager :of="$transports"/>@endif
            @if ($pick)
                <x-ui.action-bar class="action-bar--pick" data-pick-target="bar" hidden>
                    <div class="contents" data-controller="sheet">
                        <x-ui.button type="button" variant="secondary" class="min-w-0 flex-1" data-action="sheet#open">Отменить</x-ui.button>
                        <x-ui.sheet id="transports-cancel" title="Отменить вывоз">
                            <form method="post" action="/transports/cancel" class="flex flex-col gap-4" data-action="formdata->pick#carry">
                                @csrf
                                <div class="fields"><x-ui.field name="reason" id="transports-cancel-reason" label="Причина" type="textarea"/></div>
                                <x-ui.button type="submit" variant="danger" block>Отменить вывоз</x-ui.button>
                            </form>
                        </x-ui.sheet>
                    </div>
                    <div class="contents" data-controller="sheet">
                        <x-ui.button type="button" variant="secondary" class="min-w-0 flex-1" data-action="sheet#open">Уже вывезли</x-ui.button>
                        <x-ui.sheet id="transports-backdate" title="Уже вывезли" tall>
                            <x-logistics.backdate-form action="/transports/backdate" prefix="batch-backdate" data-action="formdata->pick#carry">@method('PUT')</x-logistics.backdate-form>
                        </x-ui.sheet>
                    </div>
                    <div class="contents" data-controller="sheet">
                        <x-ui.button type="button" class="min-w-0 flex-[1.4]" data-action="sheet#open">Назначить <span class="nums" data-pick-target="count"></span></x-ui.button>
                        <x-ui.sheet id="transports-assign" title="Назначить вывоз">
                            <x-logistics.assign-form action="/transports/executor" prefix="batch" :route="false" data-action="formdata->pick#carry">@method('PUT')</x-logistics.assign-form>
                        </x-ui.sheet>
                    </div>
                </x-ui.action-bar>
            @endif
        @endif
    </div>
</x-ui.shell>
