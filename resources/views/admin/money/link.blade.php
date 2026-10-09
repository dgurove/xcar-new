{{-- Карточка ссылки на оплату — строка «По ссылкам» (09.10.2026): кто платит, сумма, что со ссылкой, откуда (сделка,
     гараж, вручную — ссылкой туда), ТС с номером, счёт, кто взял. Ниже — сама ссылка (`x-billing.pay-status`: адрес,
     «Скопировать», «Отправить» с текстом «ФИО, ТС, сумма», шторка с QR и другой суммой), что делал плательщик. У ручной —
     «ТС»: выбрать машину системы (оплата засчитается в её сделку) или вписать словами, в том числе у оплаченной. --}}
@php
    use App\Support\Money; use App\Http\Admin\MoneyLinks as L; use App\Billing\Acquiring\PayLinkState; use App\Billing\InvoiceState;
    $i = $invoice;
    $offer = $i->carOffer();
    [$car, $number] = L::car($link);
    [$state, $tone] = L::state($link);
    $by = L::creator($link);
    $deal = $i->deal ?? $i->creditDeal;
    $manual = $link->origin() === 'manual';
    $from = match (true) {
        (bool) $deal => '/offers/'.$deal->offer?->number,
        $link->origin() === 'garage' && $offer => '/work/garage?preset=all&peek='.$offer->number,
        default => null,
    };
@endphp
<x-ui.detail>
    <x-ui.row-card :href="'/work/money/invoices/'.$i->id" :title="$link->payerName()" :photo="$offer?->mainPhoto()" action="Открыть счёт">
        <x-slot:marks>
            <span class="tag nums font-semibold">{{ Money::rub($link->amount) }}</span>
            <x-ui.state :tone="match (true) { $link->state === PayLinkState::Paid => 'open', $link->state === PayLinkState::Canceled => 'closed', default => $link->stateLine()[1] ?? 'plain' }">{{ $state }}</x-ui.state>
            @if ($from)<a href="{{ $from }}" class="tag" data-turbo-frame="_top">{{ L::from($link) }}</a>@else<span class="tag">{{ L::from($link) }}</span>@endif
            @if ($car)<span class="tag">{{ $car }}@if ($number), № {{ $number }}@endif</span>@endif
            <span class="tag nums">счёт {{ $i->label() }}@if ($i->state === InvoiceState::Void), аннулирован@endif</span>
            @if ($by)<x-ui.person :user="$by"/>@endif
            <span class="tag nums">{{ $link->created_at->translatedFormat('j M, H:i') }}</span>
        </x-slot:marks>

        <div class="list mt-4">
            <x-billing.pay-status :invoice="$i" :link="$link" staff/>
        </div>
        @if ($link->attempts->isNotEmpty())
            <div class="mt-4"><x-billing.link-attempts :link="$link" :limit="20"/></div>
        @endif

        @if ($manual && $i->state !== InvoiceState::Void)
            {{-- ТС ручной ссылки: машина системы — засчитать в её сделку (подбор станет меньше), иначе словами. --}}
            <form method="post" action="/work/money/links/{{ $link->id }}/car" class="mt-4 flex flex-col gap-3"
                  data-turbo-confirm="{{ $i->paid > 0 ? 'Оплата засчитается в сделку этой ТС, если она есть: сделка выставит на неё меньше' : 'Сохранить ТС у ссылки?' }}">
                @csrf @method('put')
                <div class="list-cap">ТС</div>
                <div class="fields">
                    <x-ui.combobox name="offer_id" text-name="car_title" label="ТС" url="/work/money/cars" placeholder="Марка, модель или номер"
                        :value="$i->offer_id" :text="$i->offer ? trim($i->offer->title().' '.$i->offer->year) : $i->car_title"/>
                </div>
                @if ($i->creditDeal)
                    <a href="/offers/{{ $i->creditDeal->offer?->number }}" class="row" data-turbo-frame="_top">
                        <span class="min-w-0 flex-1">Засчитана в сделку<span class="row-sub">{{ $i->creditDeal->buyer?->name }}</span></span>
                        <x-ui.chevron/>
                    </a>
                @endif
                <x-ui.button variant="secondary" block>Сохранить ТС</x-ui.button>
            </form>
        @endif
    </x-ui.row-card>
    {{-- Действие в карточке меняет список «По ссылкам» (новая сумма, отмена, ТС): страница перечитывается морфом. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
