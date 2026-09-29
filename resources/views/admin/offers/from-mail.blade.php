{{-- Разбор письма в CRM перед «Завести» — раскладкой как на парковке (`/requests/new?candidate=`): заголовок — машина,
     под ним теги письма (x-mail.letter-tags), письма цепочки лентой слева, форма справа липкой колонкой. Над полями —
     документы письма чипами, оценка открывается шторкой сама. Поля черновика — из писем, документов во вложениях и VIN.
     Новое письмо цепочки говорит иначе — его значение чипом под своим полем ($take). Предложение с тем же убытком
     или VIN уже есть — вместо полей оно само и «Привязать письма». --}}
@php
    $auto = collect($docs)->firstWhere('type', 'pdf');
    $take ??= [];
    $grid = 'grid grid-cols-2 gap-3';
    $vinSpan = 'col-span-2';
@endphp
<x-ui.shell title="Разбор письма" :heading="$candidate->title().($offer->year ? ', '.$offer->year : '')" :back="['Из писем', '/offers/from-mail']">
    <div class="-mt-3 mb-5 sm:-mt-4">
        <x-mail.letter-tags :candidate="$candidate" :letter="$letter" :waits="$waits"/>
    </div>
    @if ($errors->any())<p class="field-error mb-4">{{ $errors->first() }}</p>@endif
    {{-- Форма первой: на телефоне поля сразу, письма ниже. Две колонки — по ширине содержимого (@container): с открытой
         справа шторкой документов места на них нет, и форма встаёт над письмами. --}}
    <div class="@container">
    <div class="grid items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_28rem]">
        <div class="flex min-w-0 flex-col gap-4 @4xl:sticky @4xl:top-24 @4xl:col-start-2 @4xl:row-start-1 @4xl:max-h-[calc(100dvh-8rem)] @4xl:overflow-y-auto @4xl:-mr-2 @4xl:pr-2">
            @if ($docs)
                <div class="pills shrink-0">
                    @foreach ($docs as $doc)
                        <x-ui.doc :doc="$doc" :auto="$doc === $auto" class="pill pill-plain min-w-0 max-w-[14rem] gap-1.5"><x-ui.icon :name="match ($doc['type']) { 'letter' => 'mail', 'photos' => 'photo', default => 'file' }" class="size-4 shrink-0"/><span class="truncate">{{ $doc['label'] }}</span></x-ui.doc>
                    @endforeach
                </div>
            @endif

            @if ($existing)
                <x-ui.card title="Предложение уже есть">
                    <a href="/offers/{{ $existing->number }}" class="row">
                        <span class="min-w-0 flex-1 truncate">{{ $existing->titleWithYear() }}</span>
                        @if ($existing->published_at)<span class="tag nums shrink-0">№ {{ $existing->number }}</span>@endif
                        <span class="tag shrink-0">{{ $existing->state->label() }}</span>
                    </a>
                </x-ui.card>
                <div class="flex items-center gap-2">
                    <form method="post" action="/offers/from-mail/{{ $candidate->id }}/create" class="contents">@csrf<x-ui.button class="min-w-0 flex-1">Привязать письма</x-ui.button></form>
                    <form method="post" action="/offers/from-mail/{{ $candidate->id }}/decline" class="contents">@csrf<button class="btn btn-ghost shrink-0" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">Не заявка</button></form>
                </div>
            @else
                <form method="post" action="/offers/from-mail/{{ $candidate->id }}/create" id="review-form" class="flex min-w-0 flex-col gap-4" data-controller="vin next take">
                    @csrf
                    <input type="hidden" name="from_review" value="1">
                    <x-ui.card title="Транспортное средство">
                        @include('admin.offers.fields.car')
                    </x-ui.card>
                    <x-ui.card title="Предложение">
                        <div class="{{ $grid }}">
                            <x-ui.field name="claim_ref" label="Номер убытка" :value="$offer->claim_ref" autocapitalize="characters" autocorrect="off" spellcheck="false"/>
                            <x-ui.field name="floor_price" label="Закупочная, ₽" :take="$take['floor_price'] ?? null" data-controller="digits" data-action="input->digits#format" :value="$offer->floor_price"/>
                            <x-ui.field name="vendor_id" label="Вендор" :options="$vendors" placeholder="—" :value="$offer->vendor_id" span="col-span-2"/>
                            <x-ui.field name="insured_name" label="Страхователь" :value="$offer->insured_name" span="col-span-2"/>
                            <x-ui.field name="insured_phone" label="Телефон" type="tel" :value="$offer->insured_phone" span="col-span-2 sm:col-span-1"/>
                            <x-ui.field name="insurer_deadline_at" label="Срок страховой" type="date" :value="$offer->insurer_deadline_at?->format('Y-m-d')" span="col-span-2 sm:col-span-1"/>
                            <div class="col-span-full">
                                <input type="hidden" name="prices_include_vat" value="0">
                                <x-ui.check name="prices_include_vat" :checked="(bool) $offer->prices_include_vat">С НДС</x-ui.check>
                            </div>
                        </div>
                    </x-ui.card>
                </form>
                <div class="flex items-center gap-2">
                    <x-ui.button form="review-form" class="min-w-0 flex-1">Завести черновик</x-ui.button>
                    <form method="post" action="/offers/from-mail/{{ $candidate->id }}/decline" class="contents">@csrf<button class="btn btn-ghost shrink-0" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">Не заявка</button></form>
                </div>
            @endif
        </div>
        <div class="flex min-w-0 flex-col gap-4 @4xl:col-start-1 @4xl:row-start-1">
            <x-ui.card title="Письма" :count="$messages->count()">
                <x-mail.chain :messages="$messages" :candidate="$candidate" :base="$base" :focus="false" fold reply/>
            </x-ui.card>
        </div>
    </div>
    </div>
</x-ui.shell>
