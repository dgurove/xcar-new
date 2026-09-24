{{-- Карточка вендора в CRM — продажа предложений: условия сделки и маршруты. Имя с чипами фактов и «Изменить»
     круглой кнопкой одной строкой, пилюли Обзор · Маршруты лентой. Реквизиты, хранение, контакты, прайс, деньги —
     на парковке (`/vendors/{id}`), ссылкой в обзоре. --}}
@php use App\Vendors\{DealFormat, RewardKind}; @endphp
<x-ui.cabinet :title="$vendor->name" :back="['Вендоры', '/settings/vendors']">
    <div data-controller="sheet">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl leading-tight">{{ $vendor->name }}</h1>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <x-ui.pill :tone="$vendor->is_active ? 'plain' : 'closed'" class="!min-h-0 !py-0.5 text-xs">{{ $vendor->is_active ? $vendor->kind->label() : 'выключен' }}</x-ui.pill>
                    <span class="chip text-xs">{{ $vendor->deal_format->label() }}</span>
                    @if ($vendor->silence_means_buy)<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">молчание = покупка</x-ui.pill>@endif
                </div>
            </div>
            <button type="button" class="btn btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
        </div>
        <x-ui.sheet id="vendor-form" title="Продажа" :open="$errors->hasAny(['deal_format', 'reward_value', 'answer_hours', 'binding_days'])">
            <form method="post" action="{{ $base }}" class="flex flex-col gap-4">
                @csrf @method('put')
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.field name="deal_format" label="Формат сделки" :options="DealFormat::options()" :value="$vendor->deal_format->value" span="col-span-2"/>
                    <x-ui.field name="reward_kind" label="Вознаграждение" :options="RewardKind::options()" placeholder="—" :value="$vendor->reward_kind?->value"/>
                    <x-ui.field name="reward_value" label="Процент или сумма" :value="$vendor->reward_value" inputmode="numeric"/>
                    <x-ui.field name="answer_hours" label="Ответ, часов" :value="$vendor->answer_hours" inputmode="numeric"/>
                    <x-ui.field name="binding_days" label="Предложение держим, дней" :value="$vendor->binding_days" inputmode="numeric"/>
                    <div class="col-span-2"><x-ui.check name="silence_means_buy" :checked="$vendor->silence_means_buy">Молчание — обязанность купить</x-ui.check></div>
                </div>
                <x-ui.button block>Сохранить</x-ui.button>
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
