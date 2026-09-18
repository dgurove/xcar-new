@php use App\Park\{VehicleState, RequestType}; use App\Support\Money; $photos = $vehicle->visiblePhotos(); $docs = $vehicle->docsRequired(); @endphp
<x-ui.shell :title="$vehicle->titleWithYear()" :back="['ТС', '/cars']" cache="no-cache">
    <div class="-mt-3 mb-6 flex flex-wrap items-center gap-1.5" data-controller="sheet">
        <x-park.state :vehicle="$vehicle"/>
        @if ($vehicle->ref)<span class="chip">{{ $vehicle->ref }}</span>@endif
        @if ($vehicle->category)<span class="chip">{{ $vehicle->category->label() }}</span>@endif
        @if ($vehicle->oversize)<span class="chip">Негабарит</span>@endif
        @foreach ($vehicle->flagList() as $flag)<span class="chip">{{ $flag->label() }}</span>@endforeach
        @if ($storageRate)<span class="chip nums">{{ $storageRate }}</span>@endif
        @if ($vehicle->contact_phone)<a href="tel:+{{ preg_replace('/\D+/', '', $vehicle->contact_phone) }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->contact_name ? \Illuminate\Support\Str::of($vehicle->contact_name)->explode(' ')->first().' ' : '' }}{{ $vehicle->contact_phone }}</a>@endif
        @if ($threads->count())<x-ui.pill tone="plain" :href="$threads->count() === 1 ? '/mail/'.$threads->first()->id : '/mail?preset=linked&q='.urlencode($vehicle->ref ?? '')" class="!min-h-0 !py-1 text-xs"><x-ui.icon name="mail" class="size-4"/> {{ $threads->count() === 1 ? 'Письмо' : 'Писем: '.$threads->count() }}</x-ui.pill>@endif
        <button type="button" class="btn btn-s btn-quiet btn-round ml-auto" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-5"/></button>
        <x-ui.sheet id="vehicle-actions" title="Транспортное средство">
            <div class="flex flex-col gap-2">
                @if ($vehicle->state === VehicleState::Expected)
                    <x-ui.button href="/requests/new?tip=intake&mashina={{ $vehicle->id }}" block>Принять на стоянку</x-ui.button>
                @endif
                @if ($vehicle->state === VehicleState::Stored)
                    <form method="post" action="/cars/{{ $vehicle->id }}/move" class="flex items-end gap-2">@csrf
                        <x-ui.field name="yard_id" label="Стоянка" :options="$yards" :value="$vehicle->yard_id" span="flex-1"/>
                        <x-ui.button variant="secondary">Переставить</x-ui.button>
                    </form>
                    <form method="post" action="/cars/{{ $vehicle->id }}/release" class="flex items-end gap-2" data-turbo-confirm="Выдать ТС?">@csrf
                        <x-ui.field name="released_at" label="Выдача" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" span="flex-1"/>
                        <x-ui.button variant="secondary">Выдать</x-ui.button>
                    </form>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/intake" variant="ghost" block data-turbo="false" target="_blank">Акт приёма</x-ui.button>
                @endif
                @if ($vehicle->state === VehicleState::Released)
                    <x-ui.button href="/acts/{{ $vehicle->id }}/intake" variant="ghost" block data-turbo="false" target="_blank">Акт приёма</x-ui.button>
                    <x-ui.button href="/acts/{{ $vehicle->id }}/release" variant="ghost" block data-turbo="false" target="_blank">Акт выдачи</x-ui.button>
                @endif
                @foreach ($templates as $t)
                    <x-ui.button href="/mail/new?mashina={{ $vehicle->id }}&shablon={{ $t->id }}" variant="ghost" block><x-ui.icon name="send" class="size-4"/> {{ $t->name }}</x-ui.button>
                @endforeach
                <x-ui.button href="/requests/new?tip=inspection&mashina={{ $vehicle->id }}" variant="ghost" block>Новая заявка</x-ui.button>
            </div>
        </x-ui.sheet>
    </div>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <form method="post" action="/cars/{{ $vehicle->id }}" id="vehicle-form" data-controller="vin draft" class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @csrf @method('put')
            <x-ui.card title="Транспортное средство" class="order-1">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <x-ui.field name="ref" label="Номер убытка" :value="$vehicle->ref" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="vendor_id" label="Заказчик" :options="$vendors" placeholder="—" :value="$vehicle->vendor_id"/>
                    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="$vehicle->brand_id" :text="$vehicle->brand?->name" resets="#cb-model_id"/>
                    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="$vehicle->model_id" :text="$vehicle->model?->name"/>
                    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$vehicle->year"/>
                    <x-ui.field name="plate" label="Госномер" :value="$vehicle->plate" autocapitalize="characters"/>
                    <x-ui.vin :value="$vehicle->vin" span="col-span-2 lg:col-span-1"/>
                    <x-ui.field name="color" label="Цвет" :value="$vehicle->color"/>
                    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—" :value="$vehicle->category?->value"/>
                    <x-ui.field name="value" label="Оценка, ₽" :value="$vehicle->value" inputmode="numeric"/>
                    <x-ui.field name="contact_name" label="Страхователь" :value="$vehicle->contact_name"/>
                    <x-ui.field name="contact_phone" label="Телефон" type="tel" :value="$vehicle->contact_phone"/>
                    <x-ui.check name="oversize" :checked="$vehicle->oversize" class="self-end">Негабарит</x-ui.check>
                </div>
            </x-ui.card>
            <x-ui.card title="Осмотр" class="order-1">
                <div class="grid grid-cols-2 gap-3">
                    <div class="field col-span-full">
                        <span class="field-label">Повреждения</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($zones as $zone)
                                <label class="choice"><input type="checkbox" switch name="damage_zones[]" value="{{ $zone->value }}" @checked(in_array($zone->value, old('damage_zones', $vehicle->damage_zones ?? [])))><span>{{ $zone->label() }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <x-ui.field name="damage_note" label="Что ещё заметили" type="textarea" :value="$vehicle->damage_note" span="col-span-full sm:col-span-1"/>
                    <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vehicle->notes" span="col-span-full sm:col-span-1"/>
                </div>
            </x-ui.card>
        </form>
        @if ($docs)
        <x-ui.card title="Вендору после приёма" class="order-1">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($docs as $doc)
                    <form method="post" action="/cars/{{ $vehicle->id }}/docs" class="contents">@csrf<input type="hidden" name="doc" value="{{ $doc->value }}"><button class="chip {{ $vehicle->docDone($doc) ? 'bg-accent-soft text-accent-text' : '' }}">@if ($vehicle->docDone($doc))<x-ui.icon name="check" class="size-3.5"/>@endif {{ $doc->label() }}</button></form>
                @endforeach
            </div>
            @if ($vehicle->vendor?->intake_note)<p class="mt-3 text-sm text-ink-muted">{{ $vehicle->vendor->intake_note }}</p>@endif
        </x-ui.card>
        @endif

        <x-ui.card title="Фотографии" class="order-2 lg:col-span-2" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
            <input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.gallery')
        </x-ui.card>
        <x-ui.card title="Документы" class="order-3 lg:col-span-2" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media" data-photos-collection-value="papers">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
            <div class="mb-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button></div>
            <div hidden data-photos-target="progress" class="mb-3">
                <div class="mb-1 text-sm text-ink-muted" data-label></div>
                <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
            </div>
            @include('park.vehicles.papers')
        </x-ui.card>

        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @if ($vehicle->requests->isNotEmpty())
            <x-ui.card title="Заявки" class="order-4">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($vehicle->requests as $r)
                        <a href="/requests/{{ $r->id }}" class="flex items-center gap-2 py-2">
                            <span class="chip {{ $r->isOpen() ? 'bg-accent-soft text-accent-text' : 'bg-closed-soft text-closed' }}">{{ $r->type->label() }}</span>
                            <span class="text-sm text-ink-muted">{{ $r->isOpen() ? ($r->planned_at?->translatedFormat('j M, H:i') ?? 'ждёт') : $r->state->label() }}</span>
                            <x-ui.icon name="chevron-right" class="ml-auto size-5 text-ink-dim"/>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            <x-ui.card title="История" class="order-5">
                <form method="post" action="/cars/{{ $vehicle->id }}/note" class="mb-3 flex gap-2">@csrf
                    <input name="text" class="field-input flex-1" placeholder="Заметка" required>
                    <x-ui.button size="sm" variant="secondary">Записать</x-ui.button>
                </form>
                <div class="flex flex-col gap-2 text-sm">
                    @foreach ($vehicle->events as $event)
                        <div class="flex gap-3">
                            <span class="shrink-0 text-ink-dim">{{ $event->created_at->translatedFormat('j M H:i') }}</span>
                            <span class="min-w-0">{{ $event->text() }}</span>
                            @if ($event->user)<span class="ml-auto shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    </div>
    <x-ui.action-bar><x-ui.button form="vehicle-form" class="min-w-0 flex-1">Сохранить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
