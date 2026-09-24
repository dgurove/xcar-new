{{-- Карточка вендора: справа шапка-контакт с чипами фактов и «Изменить» одной шторкой по группам;
     слева пилюли Обзор · Маршруты · Контакты · Тарифы. Пустые блоки не рисуются. --}}
@php
    use App\Vendors\{Kind, DealFormat, RewardKind, Parser, ContactRole};
    use App\Support\Money;
    use App\Billing\Cadence;
    $claims = $vendor->defaultContact(ContactRole::Claims, ContactRole::Sales);
@endphp
<x-ui.cabinet :title="$vendor->name" :back="['Вендоры', '/settings/vendors']">
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="lg:col-start-2 lg:row-start-1" data-controller="sheet">
            <x-ui.contact :name="$vendor->name" icon="deal" sidebar class="[&_.acts]:basis-full [&_.acts]:justify-start sm:[&_.acts]:basis-auto lg:[&_.acts]:justify-center">
                <x-slot:chips>
                    <x-ui.pill :tone="$vendor->is_active ? 'plain' : 'closed'" class="!min-h-0 !py-0.5 text-xs">{{ $vendor->is_active ? $vendor->kind->label() : 'выключен' }}</x-ui.pill>
                    <span class="chip text-xs">{{ $vendor->deal_format->label() }}</span>
                    @if ($vendor->silence_means_buy)<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">молчание = покупка</x-ui.pill>@endif
                    @if ($vendor->agreementExpired())<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">договор истёк</x-ui.pill>@endif
                </x-slot:chips>
                <x-slot:acts>
                    @if ($claims?->phone)<a href="tel:+{{ $claims->phoneDigits() }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>@endif
                    @if ($claims?->email)<a href="/work/mail/new?to={{ urlencode($claims->email) }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="mail"/></span>Написать</a>@endif
                    <button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="edit"/></span>Изменить</button>
                </x-slot:acts>
            </x-ui.contact>
            <x-ui.sheet id="vendor-form" title="Вендор" wide :open="$errors->hasAny(['name', 'inn', 'kpp', 'bank_account', 'bank_corr', 'bank_bic', 'senders', 'agreement_until'])">
                <form method="post" action="{{ $base }}" class="flex flex-col gap-4">
                    @csrf @method('put')
                    @php $g = 'grid grid-cols-2 gap-3'; @endphp
                    <div class="{{ $g }}">
                        <x-ui.field name="name" label="Название" :value="$vendor->name" required span="col-span-2"/>
                        <x-ui.field name="kind" label="Тип" :options="Kind::options()" :value="$vendor->kind->value"/>
                        <x-ui.field name="legal_name" label="Юрлицо" :value="$vendor->legal_name"/>
                        <x-ui.field name="inn" label="ИНН" :value="$vendor->inn" inputmode="numeric"/>
                        <x-ui.field name="kpp" label="КПП" :value="$vendor->kpp" inputmode="numeric"/>
                        <x-ui.field name="legal_address" label="Юридический адрес" :value="$vendor->legal_address" span="col-span-2"/>
                        <x-ui.check name="is_active" :checked="$vendor->is_active" class="col-span-2">Работаем</x-ui.check>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">Реквизиты</x-ui.section-title>
                    <div class="{{ $g }}">
                        <x-ui.field name="bank_name" label="Банк" :value="$vendor->bank_name" span="col-span-2"/>
                        <x-ui.field name="bank_account" label="Расчётный счёт" :value="$vendor->bank_account" inputmode="numeric"/>
                        <x-ui.field name="bank_corr" label="Корр. счёт" :value="$vendor->bank_corr" inputmode="numeric"/>
                        <x-ui.field name="bank_bic" label="БИК" :value="$vendor->bank_bic" inputmode="numeric"/>
                        <x-ui.field name="payment_purpose" label="Назначение платежа" :value="$vendor->payment_purpose" span="col-span-2" placeholder="Оплата за поврежденное ТС по договору комиссии. НДС не облагается"/>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">Договор</x-ui.section-title>
                    <div class="{{ $g }}">
                        <x-ui.field name="agreement_number" label="Номер" :value="$vendor->agreement_number" span="col-span-2"/>
                        <x-ui.field name="agreement_date" label="Дата" type="date" :value="$vendor->agreement_date?->toDateString()"/>
                        <x-ui.field name="agreement_until" label="Действует до" type="date" :value="$vendor->agreement_until?->toDateString()"/>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">Условия</x-ui.section-title>
                    <div class="{{ $g }}">
                        <x-ui.field name="deal_format" label="Формат сделки" :options="DealFormat::options()" :value="$vendor->deal_format->value"/>
                        <x-ui.field name="reward_kind" label="Вознаграждение" :options="RewardKind::options()" placeholder="—" :value="$vendor->reward_kind?->value"/>
                        <x-ui.field name="reward_value" label="Процент или сумма" :value="$vendor->reward_value" inputmode="numeric"/>
                        <x-ui.field name="payment_days" label="Оплата, рабочих дней" :value="$vendor->payment_days" inputmode="numeric"/>
                        <x-ui.field name="answer_hours" label="Ответ, часов" :value="$vendor->answer_hours" inputmode="numeric"/>
                        <x-ui.field name="binding_days" label="Предложение держим, дней" :value="$vendor->binding_days" inputmode="numeric"/>
                        <x-ui.check name="vat_included" :checked="$vendor->vat_included">Цены с НДС</x-ui.check>
                        <x-ui.check name="silence_means_buy" :checked="$vendor->silence_means_buy">Молчание — обязанность купить</x-ui.check>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">Хранение</x-ui.section-title>
                    {{-- Опоздавший покупатель платит только там, где так в договоре (ВСК): срок хранения за счёт
                         вендора вписывают на самой ТС из письма страховой, множитель нужен только при этой галке. --}}
                    <div class="{{ $g }} buyer-rule">
                        <x-ui.field name="storage_payer" label="Хранение платит" :options="['vendor' => 'Вендор', 'owner' => 'Страхователь', 'nobody' => 'Никто']" :value="$vendor->storage_payer"/>
                        <x-ui.field name="billing_cadence" label="Счёт за хранение" :options="Cadence::options()" :value="$vendor->billing_cadence->value"/>
                        <div class="col-span-2 flex flex-wrap items-center gap-x-6 gap-y-3">
                            <x-ui.check name="buyer_pays_late" :checked="$vendor->buyer_pays_late">Опоздавший покупатель платит</x-ui.check>
                            <x-ui.check name="release_without_payment" :checked="$vendor->release_without_payment">Выдавать без оплаты</x-ui.check>
                            <x-ui.check name="release_by_qr" :checked="$vendor->release_by_qr">Выдача по QR</x-ui.check>
                        </div>
                        <div data-late>
                            <x-ui.field name="buyer_rate_multiplier" label="Опоздавший платит, × прайс" :value="rtrim(rtrim(number_format($vendor->buyer_rate_multiplier, 2, '.', ''), '0'), '.')" inputmode="decimal"/>
                        </div>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">Почта</x-ui.section-title>
                    <div class="{{ $g }}">
                        <x-ui.field name="senders" label="Отправители: домены и адреса, по одному в строке" type="textarea" :value="implode(PHP_EOL, $vendor->senders ?? [])" span="col-span-2" placeholder="alfastrah.ru"/>
                        <x-ui.field name="parser" label="Разбор писем" :options="Parser::options()" :value="$vendor->parser->value" span="col-span-2"/>
                        <x-ui.field name="mail_account_id" label="Отвечаем с ящика" :options="$accounts" placeholder="Первый активный" :value="$vendor->mail_account_id" span="col-span-2"/>
                        <x-ui.field name="report_template_id" label="Письмо о приёме" :options="$templates" placeholder="«Приём на парковку»" :value="$vendor->report_template_id"/>
                        <x-ui.field name="refusal_template_id" label="Письмо об отказе" :options="$templates" placeholder="«Отказ от получения»" :value="$vendor->refusal_template_id"/>
                    </div>
                    <x-ui.section-title level="h3" class="!text-lg">После приёма присылаем</x-ui.section-title>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($docs as $doc)
                            <label class="choice"><input type="checkbox" switch name="intake_docs[]" value="{{ $doc->value }}" @checked(in_array($doc->value, old('intake_docs', $vendor->intake_docs ?? []), true))><span>{{ $doc->label() }}</span></label>
                        @endforeach
                    </div>
                    <x-ui.field name="intake_note" label="Что ещё просит" type="textarea" :value="$vendor->intake_note"/>
                    <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vendor->notes"/>
                    <x-ui.button block>Сохранить</x-ui.button>
                </form>
                @if (!$vendor->offers()->exists() && !$vendor->vehicles()->exists())
                    <form method="post" action="{{ $base }}" class="mt-3" data-turbo-confirm="Удалить вендора?">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить</x-ui.button></form>
                @endif
            </x-ui.sheet>
        </div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            <div class="mb-5 flex flex-wrap gap-1.5">
                @foreach ($pills as $key => $label)
                    <x-ui.pill :href="$base.($key === 'overview' ? '' : '?pill='.$key)" :current="$pill === $key">{{ $label }}</x-ui.pill>
                @endforeach
            </div>
            @if ($errors->has('vendor'))<span class="field-error mb-4 block">{{ $errors->first('vendor') }}</span>@endif
            @include('admin.vendors.'.$pill)
        </div>
    </div>
</x-ui.cabinet>
