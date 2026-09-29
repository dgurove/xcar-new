{{-- Разбор письма в CRM перед «Завести» (как `/requests/new?candidate=` на парковке): поля черновика из писем — разбор,
     документы во вложениях (акт, договор, оценка), VIN — поправить и завести. Над полями — документы письма чипами,
     скан открывается шторкой сам. Новое письмо цепочки говорит иначе — его значение кнопкой «Взять» у поля.
     Предложение с тем же убытком или VIN уже есть — вместо полей оно само и «Привязать письма». --}}
@php
    $grid = 'grid grid-cols-2 gap-3 @4xl:grid-cols-3';
    $auto = collect($docs)->firstWhere('type', 'pdf');
@endphp
<x-ui.shell title="Разбор письма" :heading="$candidate->title().($offer->year ? ', '.$offer->year : '')" :back="['Из писем', '/offers/from-mail']">
    @if ($errors->any())<p class="field-error mb-4">{{ $errors->first() }}</p>@endif
    <div class="@container">
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        @if ($docs)
            <div class="pills">
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
                <form method="post" action="/offers/from-mail/{{ $candidate->id }}/create" class="mt-3">@csrf<x-ui.button>Привязать письма</x-ui.button></form>
            </x-ui.card>
        @else
            <form method="post" action="/offers/from-mail/{{ $candidate->id }}/create" id="review-form" class="flex flex-col gap-4" data-controller="vin next take">
                @csrf
                <input type="hidden" name="from_review" value="1">
                @if ($proposed)
                    {{-- Поле, о котором новое письмо говорит другое: его значение — кнопкой, подставить одним нажатием. --}}
                    <div class="box-nested box-urgent flex flex-col gap-1.5">
                        @foreach ($proposed as $input => [$label, $shown, $value])
                            <div class="flex items-center gap-2 text-sm" data-take-row>
                                <span class="min-w-0 flex-1 truncate text-ink-muted">{{ $label }}</span>
                                <button type="button" class="chip nums" data-action="take#take" data-take-name-param="{{ $input }}" data-take-value-param="{{ $value }}">{{ $shown }}</button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <x-ui.card title="Транспортное средство">
                    @include('admin.offers.fields.car')
                </x-ui.card>
                <x-ui.card title="Предложение">
                    <div class="{{ $grid }}">
                        <x-ui.field name="claim_ref" label="Номер убытка" :value="$offer->claim_ref" autocapitalize="characters" autocorrect="off" spellcheck="false"/>
                        <x-ui.field name="vendor_id" label="Вендор" :options="$vendors" placeholder="—" :value="$offer->vendor_id"/>
                        <x-ui.field name="floor_price" label="Закупочная, ₽" inputmode="numeric" data-controller="digits" data-action="input->digits#format" :value="$offer->floor_price"/>
                        <x-ui.field name="insurer_deadline_at" label="Срок страховой" type="date" :value="$offer->insurer_deadline_at?->format('Y-m-d')"/>
                        <x-ui.field name="insured_name" label="Страхователь" :value="$offer->insured_name"/>
                        <x-ui.field name="insured_phone" label="Телефон" type="tel" :value="$offer->insured_phone"/>
                        <div class="col-span-full">
                            <input type="hidden" name="prices_include_vat" value="0">
                            <x-ui.check name="prices_include_vat" :checked="(bool) $offer->prices_include_vat">С НДС</x-ui.check>
                        </div>
                    </div>
                </x-ui.card>
            </form>
            <div class="flex items-center gap-2">
                <x-ui.button form="review-form" class="min-w-0 flex-1">Завести</x-ui.button>
                <form method="post" action="/offers/from-mail/{{ $candidate->id }}/decline" class="contents">@csrf<button class="btn btn-ghost shrink-0" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">Не заявка</button></form>
            </div>
        @endif
    </div>
    </div>
</x-ui.shell>
