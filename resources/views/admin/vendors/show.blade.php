{{-- Карточка вендора в CRM — продажа предложений. Имя с чипами фактов и «Изменить» круглой кнопкой одной строкой,
     пилюли Обзор, Контакты, Маршруты лентой. Шторка «Продажа»: имя и тип (общие с парковкой), условия сделки, почта
     продажи. Реквизиты, хранение, прайс, деньги, заявки на приёмку — на парковке, ссылкой в обзоре.
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@php use App\Vendors\{DealFormat, Kind, Parser, RewardKind}; @endphp
<x-ui.cabinet :title="$vendor->name" :back="['Вендоры', '/settings/vendors']" :phone-heading="false">
    <div data-controller="sheet">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl leading-tight"><x-vendor.name :vendor="$vendor"/></h1>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <x-ui.state :tone="$vendor->is_active ? 'plain' : 'closed'">{{ $vendor->is_active ? $vendor->kind->label() : 'выключен' }}</x-ui.state>
                    <span class="chip text-xs">{{ $vendor->deal_format->label() }}</span>
                    @if ($vendor->silence_means_buy)<x-ui.state tone="urgent">молчание = покупка</x-ui.state>@endif
                </div>
            </div>
            <button type="button" class="btn btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
        </div>
        <x-ui.sheet id="vendor-form" title="Продажа" wide tall :open="$errors->hasAny(['name', 'logo', 'deal_format', 'reward_value', 'answer_hours', 'binding_days', 'senders', 'mail_account_id'])">
            <form method="post" action="{{ $base }}" enctype="multipart/form-data" class="flex flex-col gap-3">
                @csrf @method('put')
                <x-vendor.logo-field :vendor="$vendor"><x-ui.field name="name" label="Название" :value="$vendor->name" required/></x-vendor.logo-field>
                <div class="fields">
                    <x-ui.field name="kind" label="Тип" :options="Kind::options()" :value="$vendor->kind->value"/>
                </div>
                <x-ui.check name="is_active" :checked="$vendor->is_active">Работаем</x-ui.check>
                <div class="list-head">Сделка</div>
                <div class="fields">
                    <x-ui.field name="deal_format" label="Формат сделки" :options="DealFormat::options()" :value="$vendor->deal_format->value"/>
                    <x-ui.field name="reward_kind" label="Вознаграждение" :options="RewardKind::options()" placeholder="—" :value="$vendor->reward_kind?->value"/>
                    <x-ui.field name="reward_value" label="Процент или сумма" :value="$vendor->reward_value"/>
                    <x-ui.field name="answer_hours" label="Ответ, часов" :value="$vendor->answer_hours"/>
                    <x-ui.field name="binding_days" label="Срок предложения, дней" :value="$vendor->binding_days"/>
                </div>
                {{-- Волны показа его предложений, у которых нет своих: группы менеджеров и люди, через сколько. --}}
                <div class="flex flex-col gap-1.5" data-controller="audience" data-audience-options-value="{{ json_encode($audienceOptions) }}"
                    data-audience-effective-value="{{ json_encode(\App\Offers\AudienceRules::normalize($vendor->audience_rules ?: \App\Offers\AudienceRules::everyone())) }}">
                    <span class="field-label">Кому показывать</span>
                    <input type="hidden" name="audience_rules" value="{{ $vendor->audience_rules ? json_encode($vendor->audience_rules) : '' }}" data-audience-target="rules">
                    <x-audience.editor/>
                </div>
                <x-ui.check name="silence_means_buy" :checked="$vendor->silence_means_buy">Молчание — обязанность купить</x-ui.check>
                <x-ui.check name="offers_include_vat" :checked="$vendor->offers_include_vat">Цены предложений с НДС</x-ui.check>
                <div class="list-head">Почта</div>
                <div class="fields">
                    <x-ui.field name="mail_account_id" label="Отвечаем с ящика" :options="$accounts" placeholder="Первый активный" :value="$vendor->mail_account_id"/>
                    <x-ui.field name="senders" label="Предложения приходят от: домены и адреса, по одному в строке" type="textarea" :value="implode(PHP_EOL, $vendor->senders ?? [])" placeholder="alfastrah.ru"/>
                    <x-ui.field name="parser" label="Разбор предложений" :options="Parser::options()" :value="$vendor->parser->value"/>
                </div>
                <div class="sheet-foot"><x-ui.button block>Сохранить</x-ui.button></div>
            </form>
        </x-ui.sheet>
    </div>
    <x-ui.pills class="mb-5 mt-5">
        @foreach ($pills as $key => $label)
            <x-ui.pill :href="$base.($key === 'overview' ? '' : '?pill='.$key)" :current="$pill === $key" data-turbo-action="replace">{{ $label }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    @include('admin.vendors.'.$pill)
</x-ui.cabinet>
