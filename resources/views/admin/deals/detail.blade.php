{{-- Карточка сделки рядом со списком (фрейм detail): почти вся сделка шириной телефона — кадры, состояние, номер убытка с
     логотипом, № предложения, телефон менеджера, сумма; сразу под шапкой — чат с менеджером (07.10.2026, владелец: «чат на
     видное место, он чуть ли не самый важный во время сделки»), дальше путь маршрута с выходами, все письма строками, деньги, ДКП,
     «Показ» и заметка — тот же порядок, что в редакторе (12.10.2026). Поля — свёрнуты («Изменить / Готово»), расклад денег — под «Расклад»; получение — одно, внутри
     «Вывоза» (без вывоза — отдельно). Менеджер — аватаром в чате, чипа в шапке нет; «Предложение» — «Открыть страницу»
     в полосе. История — на странице сделки. Формы отвечают в карточку (DetailBack), строка таблицы — свежей из row. --}}
@php
    use App\Offers\DealState;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/work/deals/'.$deal->id" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()">
        <x-slot:marks>
            {{-- Коды чипами. --}}
            <x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" copy class="tag nums"/>
            <span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $offer->number" done="Номер в буфере"/></span>
            {{-- Срок обязывающего, пока поставщику не заплачено (19.10.2026). --}}
            <x-offer.binding :offer="$offer"/>
        </x-slot:marks>
        {{-- Покупатель с телефоном ссылкой — фразой; состояние (закрыта, отменена) — у названия. --}}
        {{-- Слот и @endif — на разных строках: вплотную («</x-slot:badge>@endif») Blade склеивает их и шаблон не собирается. --}}
        @if ($deal->state !== DealState::Active)
            <x-slot:badge><x-ui.state :tone="$deal->state === DealState::Done ? 'open' : 'danger'" class="title-state">{{ mb_strtolower($deal->state->label()) }}</x-ui.state></x-slot:badge>
        @endif
        @if ($deal->buyer?->phone)
            {{-- Кто это — словом (03.11.2026, владелец: имя с телефоном без подписи читалось непонятно). --}}
            <x-slot:line><span>{{ $deal->auction_at ? 'Покупатель' : 'Менеджер' }} {{ $deal->buyer->name }}</span><x-ui.phone :user="$deal->buyer"/></x-slot:line>
        @endif
        <x-slot:aside><span class="nums text-lg font-semibold">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span></x-slot:aside>
        {{-- Ряд кнопок одним видом с окошком предложения (12.10.2026): «Чат» пилюлей вместо большой карточки, документы. --}}
        <x-slot:actions>
            @if ($deal->buyer)<x-deal.chat-card :deal="$deal" compact/>@endif
            @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
            <x-deal.person-link :deal="$deal" pill/>
            {{-- Показать менеджеру руками (пункты из машины), когда письма на разбор нет — пилюлей в том же ряду. --}}
            <x-deal.reply-review :deal="$deal" pill/>
        </x-slot:actions>
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="mt-3">{{ $errors->first('exit') }}</x-ui.flash>@endif
        <div class="mt-4 flex flex-col gap-6">
            {{-- Порядок по смыслу (03.11.2026, владелец: «много непонятного в окошке — разобраться»): что сейчас (путь) → сделка
                 (вопрос про закупочную, деньги со схемой, покупатель) → вывоз → письма → показ и заметка → опасное. --}}
            {{-- Письмо страховой ждёт разбора: менеджер о нём не знает, пока админ не покажет выбранное (03.11.2026). --}}
            {{-- Переоценка закупочной — первой (04.11.2026, владелец: в окошке сделки о ней не было ни слова). --}}
            @if (auth()->user()->canManageCrm() && ($reprice = $offer->pendingReprice))<x-offer.reprice :offer="$offer" :reprice="$reprice" class="w-full" :sheet-only="\App\Offers\RepriceFlow::inRoute($offer)"/>@endif
            {{-- С путём письмо — строкой в текущем шаге (`x-route.path`, 10.11.2026); без пути — плашкой здесь. --}}
            @if ($offer->position(\App\Workflow\Track::Sale))
                @include('admin.offers.route', ['cardClass' => '', 'untitled' => true])
            @else
                <x-deal.reply-review :deal="$deal"/>
            @endif
            <x-offer.reprice-asks :offer="$offer"/>
            <x-deal.money :deal="$deal" compact id="deal-money"/>
            @if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@elseif ($deal->collectsBuyer() || $deal->contract?->buyer_user_id)<x-deal.invoice-buyer :deal="$deal"/>@endif
            {{-- Параллельные этой машины (07.11.2026, `Parallels`): чьи реквизиты, «Взять в работу» — и в окошке, не только в редакторе. --}}
            @php $parallelCount = auth()->user()->canManageCrm() && $deal->isActive() ? $deal->parallels()->count() : 0; @endphp
            @if ($parallelCount)
                <section>
                    <h2 class="detail-section">Параллельно <span class="nums font-normal text-ink-dim">{{ $parallelCount }}</span></h2>
                    @include('admin.offers.parallels', ['offer' => $offer, 'main' => $deal, 'bare' => true])
                </section>
            @endif
            {{-- Вывоз — задача логистики с получением внутри (`x-logistics.lane`); вывоза нет — получение своим разделом. --}}
            @if ($offer->pickup())<x-logistics.lane :offer="$offer" :deal="$deal"/>@else<x-deal.handover :deal="$deal" compact/>@endif
            {{-- Письма: по сделке — строками; письма парковки (приём, выдача, заявки storage@) — своей лентой ниже (`split`). --}}
            @if ($messages->isNotEmpty())
                <section>
                    <h2 class="detail-section">Письма @if ($dealLetters)<span class="nums font-normal text-ink-dim">{{ $dealLetters }}</span>@endif</h2>
                    <x-mail.letters :messages="$messages" :url="'/offers/'.$offer->number.'/letters'" :asks="$asks" :stages="$stages" split/>
                </section>
            @endif
            {{-- «Показ» сделки — строкой со шторкой (как у черновика): сколько и кому показывать; «Сохранить изменения» подвалом. --}}
            @if ($deal->isActive() && ! $deal->isGarage() && auth()->user()->canManageCrm())
                <form method="post" action="/offers/{{ $offer->number }}" id="deal-show-{{ $offer->number }}" data-controller="save-bar" data-save-bar-partial-value="true" data-turbo-frame="detail">
                    @csrf @method('put')
                </form>
                <div class="list"><x-offer.showing-row :offer="$offer" :form="'deal-show-'.$offer->number" class="row w-full text-left"/></div>
            @endif
            <x-deal.note :deal="$deal"/>
            {{-- Сделка не состоялась (11.10.2026, владелец: «отдали в сделку — и нигде нет кнопки обратно в продажу»): назад в
                 продажу с переоценкой (`x-offer.resale-sheet`) или в черновики — красными строками внизу, как опасное в «Оплатах». --}}
            @if ($deal->state === DealState::Active)
                @if ($errors->has('state'))<x-ui.flash tone="danger">{{ $errors->first('state') }}</x-ui.flash>@endif
                {{-- Одна красная строка — шторка «Отменить сделку»: в продажу (кому и до когда) или в черновики (12.10.2026). --}}
                <div class="list">
                    {{-- Поставщик отказал продавать (28.10.2026): сделка закрыта по причине, предложение в архиве. --}}
                    <button type="button" class="row row-danger" data-controller="emit" data-action="emit#send" data-emit-event-param="declined:open">Поставщик отказал…</button>
                    <button type="button" class="row row-danger" data-controller="emit" data-action="emit#send" data-emit-event-param="resale:open">{{ $deal->isGarage() ? 'Вернуть в продажу' : 'Отменить сделку' }}</button>
                </div>
                <x-offer.resale-sheet :offer="$offer"/>
                <x-deal.declined-sheet :deal="$deal"/>
            @endif
        </div>
        @if ($deal->isActive() && ! $deal->isGarage() && auth()->user()->canManageCrm())
            <x-slot:foot><x-ui.save-bar :form="'deal-show-'.$offer->number"/></x-slot:foot>
        @endif
    </x-ui.row-card>
</x-ui.detail>
