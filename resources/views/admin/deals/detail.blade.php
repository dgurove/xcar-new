{{-- Карточка сделки рядом со списком (фрейм detail): почти вся сделка шириной телефона — кадры, состояние, номер убытка с
     логотипом, № предложения, телефон менеджера, сумма; сразу под шапкой — чат с менеджером (07.10.2026, владелец: «чат на
     видное место, он чуть ли не самый важный во время сделки»), дальше путь маршрута с выходами, деньги, ДКП, последнее
     письмо и заметка. Поля — свёрнуты («Изменить / Готово»), расклад денег — под «Расклад»; получение — одно, внутри
     «Вывоза» (без вывоза — отдельно). Менеджер — аватаром в чате, чипа в шапке нет; «Предложение» — «Открыть страницу»
     в полосе. История — на странице сделки. Формы отвечают в карточку (DetailBack), строка таблицы — свежей из row. --}}
@php
    use App\Offers\DealState;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/work/deals/'.$deal->id" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()">
        <x-slot:marks>
            @if ($deal->state !== DealState::Active)<span class="tag {{ $deal->state === DealState::Done ? 'tag-accent' : 'text-danger' }}">{{ $deal->state->label() }}</span>@endif
            @if ($vendor || $offer->claim_ref)<span class="tag"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/></span>@endif
            <span class="tag nums gap-1">№<x-ui.copy-code :value="(string) $offer->number" done="Номер в буфере"/></span>
            @if ($deal->buyer?->phone)<a href="tel:+{{ $deal->buyer->phone }}" class="tag nums">{{ $deal->buyer->phoneFormatted() }}</a>@endif
        </x-slot:marks>
        <x-slot:aside><span class="nums text-lg font-semibold">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span></x-slot:aside>
        <x-slot:actions>
            @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
        </x-slot:actions>
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="mt-3">{{ $errors->first('exit') }}</x-ui.flash>@endif
        <div class="mt-4 flex flex-col gap-6">
            @if ($deal->buyer)<x-deal.chat-card :deal="$deal"/>@endif
            @if ($offer->positions->isNotEmpty())@include('admin.offers.route', ['cardClass' => ''])@endif
            {{-- Получение — внутри «Вывоза» (`admin.offers.route`); вывоза нет — своим разделом. --}}
            @unless ($offer->position(\App\Workflow\Track::Service))<x-deal.handover :deal="$deal" compact/>@endunless
            <x-deal.money :deal="$deal" compact/>
            @if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@endif
            @if ($lastLetter)
                <section>
                    <h2 class="detail-section">Письма <span class="nums font-normal text-ink-dim">{{ $letters }}</span></h2>
                    <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$offer->number.'/letters'" :asks="$asks" compact/>
                </section>
            @endif
            <x-deal.note :deal="$deal"/>
        </div>
        <x-slot:row><x-deal.table-row :deal="$deal"/></x-slot:row>
    </x-ui.row-card>
</x-ui.detail>
