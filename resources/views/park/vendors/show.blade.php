{{-- Карточка вендора на парковке: заголовок — имя, под ним одной строкой состояние (тип, «не работаем», чего не
     хватает), пилюли Обзор · Контакты · Тарифы · Деньги лентой (не переносятся), «Изменить» — круглой кнопкой справа
     от чипов, шторкой по группам. Условия продажи и маршруты — в CRM, ссылкой в обзоре. --}}
@php
    $manage = auth()->user()->canManagePark();
    $noBank = $vendor->kind->billable() && ! \App\Billing\Party::forVendor($vendor, false)->billable();
@endphp
<x-ui.shell :title="$vendor->name" :back="['Вендоры', '/vendors']">
    <x-slot:lead><x-vendor.logo :vendor="$vendor"/></x-slot:lead>
    <div class="max-w-3xl">
        <div class="-mt-2 mb-4 flex items-center gap-1.5">
            <div class="flex min-w-0 flex-1 flex-wrap gap-1.5">
            <x-ui.pill :tone="$vendor->is_active ? 'plain' : 'closed'" class="!min-h-0 !py-0.5 text-xs">{{ $vendor->is_active ? $vendor->kind->label() : 'не работаем' }}</x-ui.pill>
            @if ($noBank)<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">нет реквизитов для счёта</x-ui.pill>@endif
            @if ($vendor->agreementExpired())<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">договор истёк</x-ui.pill>@endif
            @if ($vendor->release_by_qr)<x-ui.pill tone="soft" class="!min-h-0 !py-0.5 text-xs">выдача по QR</x-ui.pill>@endif
            </div>
            @if ($manage)<button type="button" class="btn btn-quiet btn-round shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="vendor-form:open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>@endif
        </div>
        <x-ui.pills class="mb-5">
            @foreach ($pills as $key => $label)
                <x-ui.pill :href="$base.($key === 'overview' ? '' : '?pill='.$key)" :current="$pill === $key" data-turbo-action="replace">{{ $label }}</x-ui.pill>
            @endforeach
        </x-ui.pills>
        @if ($errors->has('vendor'))<p class="field-error mb-4">{{ $errors->first('vendor') }}</p>@endif
        @include('park.vendors.'.$pill)
    </div>
    @if ($manage)
        @include('park.vendors.form')
    @endif
</x-ui.shell>
