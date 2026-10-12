{{-- Карточка сделки рядом со списком (фрейм detail): почти вся сделка шириной телефона — кадры, состояние, номер убытка с
     логотипом, № предложения, телефон менеджера, сумма; сразу под шапкой — чат с менеджером (07.10.2026, владелец: «чат на
     видное место, он чуть ли не самый важный во время сделки»), дальше путь маршрута с выходами, все письма строками, деньги, ДКП,
     «Показ» и заметка — тот же порядок, что в редакторе (12.10.2026). Поля — свёрнуты («Изменить / Готово»), расклад денег — под «Расклад»; получение — одно, внутри
     «Вывоза» (без вывоза — отдельно). Менеджер — аватаром в чате, чипа в шапке нет; «Предложение» — «Открыть страницу»
     в полосе. История — на странице сделки. Формы отвечают в карточку (DetailBack), строка таблицы — свежей из row. --}}
@php
    use App\Offers\DealState;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/work/deals/'.$deal->id" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()">
        <x-slot:marks>
            {{-- Коды чипами. --}}
            @if ($vendor || $offer->claim_ref)<x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy class="tag nums"/>@endif
            <span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $offer->number" done="Номер в буфере"/></span>
        </x-slot:marks>
        {{-- Покупатель с телефоном ссылкой — фразой; состояние (закрыта, отменена) — у названия. --}}
        {{-- Слот и @endif — на разных строках: вплотную («</x-slot:badge>@endif») Blade склеивает их и шаблон не собирается. --}}
        @if ($deal->state !== DealState::Active)
            <x-slot:badge><x-ui.state :tone="$deal->state === DealState::Done ? 'open' : 'danger'" class="title-state">{{ mb_strtolower($deal->state->label()) }}</x-ui.state></x-slot:badge>
        @endif
        @if ($deal->buyer?->phone)
            <x-slot:line><span>{{ $deal->buyer->name }}</span><a href="tel:+{{ $deal->buyer->phone }}" class="nums whitespace-nowrap text-accent-text">{{ $deal->buyer->phoneFormatted() }}</a></x-slot:line>
        @endif
        <x-slot:aside><span class="nums text-lg font-semibold">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span></x-slot:aside>
        {{-- Ряд кнопок одним видом с окошком предложения (12.10.2026): «Чат» пилюлей вместо большой карточки, документы. --}}
        <x-slot:actions>
            @if ($deal->buyer)<x-deal.chat-card :deal="$deal" compact/>@endif
            @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
        </x-slot:actions>
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="mt-3">{{ $errors->first('exit') }}</x-ui.flash>@endif
        <div class="mt-4 flex flex-col gap-6">
            @if ($offer->positions->isNotEmpty())@include('admin.offers.route', ['cardClass' => ''])@endif
            {{-- Письма — сразу за путём (11.10.2026): шаг говорит о письме поставщику, само письмо рядом. --}}
            @if ($letters)
                <section>
                    <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ $letters }}</span></h2>
                    <x-mail.letters :messages="$messages" :url="'/offers/'.$offer->number.'/letters'" :asks="$asks" :stages="$stages"/>
                </section>
            @endif
            {{-- Получение — внутри «Вывоза» (`admin.offers.route`); вывоза нет — своим разделом. --}}
            @unless ($offer->position(\App\Workflow\Track::Service))<x-deal.handover :deal="$deal" compact/>@endunless
            <x-deal.money :deal="$deal" compact/>
            @if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@endif
            {{-- «Показ» — тот же модуль, что справа в редакторе под «Ценами» (12.10.2026): сколько и кому показывать сделку; своя
                 форма, «Сохранить изменения» подвалом карточки шлёт только тронутое. --}}
            @if ($deal->isActive() && ! $deal->isGarage() && auth()->user()->canManageCrm())
                <form method="post" action="/offers/{{ $offer->number }}" id="deal-show-{{ $offer->number }}" data-controller="save-bar" data-save-bar-partial-value="true" data-turbo-frame="detail">
                    @csrf @method('put')
                    <x-offer.showing :offer="$offer" :form="'deal-show-'.$offer->number" as="section"/>
                </form>
            @endif
            <x-deal.note :deal="$deal"/>
            {{-- Сделка не состоялась (11.10.2026, владелец: «отдали в сделку — и нигде нет кнопки обратно в продажу»): назад в
                 продажу с переоценкой (`x-offer.resale-sheet`) или в черновики — красными строками внизу, как опасное в «Оплатах». --}}
            @if ($deal->state === DealState::Active)
                @if ($errors->has('state'))<x-ui.flash tone="danger">{{ $errors->first('state') }}</x-ui.flash>@endif
                <div class="list">
                    <button type="button" class="row row-danger" data-controller="emit" data-action="emit#send" data-emit-event-param="resale:open">Вернуть в продажу</button>
                    <form method="post" action="/offers/{{ $offer->number }}/state" class="contents" data-turbo-confirm="Отменить сделку?" data-turbo-confirm-text="ТС уйдёт в черновики, подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются">
                        @csrf<input type="hidden" name="state" value="draft"><button class="row row-danger">Отменить сделку, в черновики</button>
                    </form>
                </div>
                <x-offer.resale-sheet :offer="$offer"/>
            @endif
        </div>
        @if ($deal->isActive() && ! $deal->isGarage() && auth()->user()->canManageCrm())
            <x-slot:foot><x-ui.save-bar :form="'deal-show-'.$offer->number"/></x-slot:foot>
        @endif
        <x-slot:row><x-deal.table-row :deal="$deal"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
