{{-- «Изменить» вендора — одна шторка по группам: основное, реквизиты, договор, хранение, заявки и письма парковки,
     после приёма. Открывается кнопкой в ряду заголовка (событие vendor-form:open). Почта продажи — в CRM. --}}
@php use App\Vendors\{Kind, Parser}; use App\Billing\Cadence; @endphp
<div data-controller="sheet" data-action="vendor-form:open@window->sheet#open" class="contents">
    <x-ui.sheet id="vendor-form" title="Вендор" wide :open="$errors->hasAny(['name', 'logo', 'inn', 'kpp', 'bank_account', 'bank_corr', 'bank_bic', 'park_senders', 'agreement_until', 'buyer_rate_multiplier'])">
        <form method="post" action="{{ $base }}" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf @method('put')
            @php $g = 'grid grid-cols-2 gap-3'; @endphp
            <div class="{{ $g }}">
                <div class="col-span-2 flex flex-col gap-3"><x-vendor.logo-field :vendor="$vendor"><x-ui.field name="name" label="Название" :value="$vendor->name" required/></x-vendor.logo-field></div>
                <x-ui.field name="kind" label="Тип" :options="Kind::options()" :value="$vendor->kind->value"/>
                <div class="flex items-end pb-3.5"><x-ui.check name="is_active" :checked="$vendor->is_active">Работаем</x-ui.check></div>
            </div>
            <div class="list-head">Реквизиты</div>
            <div class="{{ $g }}">
                <x-ui.field name="legal_name" label="Юрлицо" :value="$vendor->legal_name" span="col-span-2"/>
                <x-ui.field name="inn" label="ИНН" :value="$vendor->inn" inputmode="numeric"/>
                <x-ui.field name="kpp" label="КПП" :value="$vendor->kpp" inputmode="numeric"/>
                <x-ui.field name="legal_address" label="Юридический адрес" :value="$vendor->legal_address" span="col-span-2"/>
                <x-ui.field name="bank_name" label="Банк" :value="$vendor->bank_name" span="col-span-2"/>
                <x-ui.field name="bank_account" label="Расчётный счёт" :value="$vendor->bank_account" inputmode="numeric"/>
                <x-ui.field name="bank_corr" label="Корр. счёт" :value="$vendor->bank_corr" inputmode="numeric"/>
                <x-ui.field name="bank_bic" label="БИК" :value="$vendor->bank_bic" inputmode="numeric"/>
                <x-ui.field name="payment_purpose" label="Назначение платежа" :value="$vendor->payment_purpose" span="col-span-2"/>
            </div>
            <div class="list-head">Договор</div>
            <div class="{{ $g }}">
                <x-ui.field name="agreement_number" label="Номер" :value="$vendor->agreement_number" span="col-span-2"/>
                <x-ui.field name="agreement_date" label="Дата" type="date" :value="$vendor->agreement_date?->toDateString()"/>
                <x-ui.field name="agreement_until" label="Действует до" type="date" :value="$vendor->agreement_until?->toDateString()"/>
                <x-ui.field name="payment_days" label="Оплата, рабочих дней" :value="$vendor->payment_days" inputmode="numeric"/>
                <div class="flex items-end pb-3.5"><x-ui.check name="vat_included" :checked="$vendor->vat_included">Хранение с НДС</x-ui.check></div>
            </div>
            <div class="list-head">Хранение</div>
            {{-- Опоздавший покупатель платит только там, где так в договоре: срок за счёт вендора вписывают на самой ТС,
                 множитель нужен только при этой галке. --}}
            <div class="{{ $g }} buyer-rule">
                <x-ui.field name="storage_payer" label="Платит" :options="['vendor' => 'Вендор', 'owner' => 'Страхователь', 'nobody' => 'Никто']" :value="$vendor->storage_payer"/>
                <x-ui.field name="billing_cadence" label="Счёт" :options="Cadence::options()" :value="$vendor->billing_cadence->value"/>
                <div class="col-span-2 flex flex-col gap-3">
                    <x-ui.check name="buyer_pays_late" :checked="$vendor->buyer_pays_late">Опоздавший покупатель платит</x-ui.check>
                    <div data-late><x-ui.field name="buyer_rate_multiplier" label="Опоздавший платит, × прайс" :value="rtrim(rtrim(number_format($vendor->buyer_rate_multiplier, 2, '.', ''), '0'), '.')" inputmode="decimal"/></div>
                    <x-ui.check name="release_without_payment" :checked="$vendor->release_without_payment">Выдавать без оплаты</x-ui.check>
                    <x-ui.check name="release_by_qr" :checked="$vendor->release_by_qr">Выдача по QR</x-ui.check>
                </div>
            </div>
            <div class="list-head">Заявки на приёмку</div>
            <div class="{{ $g }}">
                <x-ui.field name="park_senders" label="Приходят от: домены и адреса, по одному в строке" type="textarea" :value="implode(PHP_EOL, $vendor->park_senders ?? [])" span="col-span-2" placeholder="alfastrah.ru"/>
                <x-ui.field name="park_parser" label="Разбор заявок" :options="Parser::options()" :value="$vendor->park_parser->value" span="col-span-2"/>
                <x-ui.field name="report_template_id" label="Письмо о приёме" :options="$templates" placeholder="«Приём на парковку»" :value="$vendor->report_template_id"/>
                <x-ui.field name="refusal_template_id" label="Письмо об отказе" :options="$templates" placeholder="«Отказ от получения»" :value="$vendor->refusal_template_id"/>
            </div>
            <div class="list-head">После приёма присылаем</div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($docs as $doc)
                    <label class="choice"><input type="checkbox" switch name="intake_docs[]" value="{{ $doc->value }}" @checked(in_array($doc->value, old('intake_docs', $vendor->intake_docs ?? []), true))><span>{{ $doc->label() }}</span></label>
                @endforeach
            </div>
            <x-ui.field name="intake_note" label="Что ещё просит" type="textarea" :value="$vendor->intake_note"/>
            <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vendor->notes"/>
            <x-ui.button block>Сохранить</x-ui.button>
        </form>
        @if (! $vendor->offers()->exists() && ! $vendor->vehicles()->exists())
            <form method="post" action="{{ $base }}" class="mt-3" data-turbo-confirm="Удалить вендора?">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить</x-ui.button></form>
        @endif
    </x-ui.sheet>
</div>
