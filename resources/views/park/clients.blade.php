{{-- Вендоры глазами стоянки: контакт по хранению, что прислать после приёма, заметки. Реквизиты, договор, прайс — в CRM. --}}
@php use App\Vendors\ContactRole; @endphp
<x-ui.shell title="Вендоры" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($vendors as $vendor)
            @php $c = $vendor->contacts->firstWhere('role', ContactRole::Storage) ?? $vendor->contacts->first(); @endphp
            <div class="row" data-controller="sheet">
                <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $vendor->name }} @if ($vendor->stored_count)<span class="text-sm text-ink-muted tabular-nums">{{ $vendor->stored_count }}</span>@endif</span>
                        <span class="row-sub mt-1.5 flex flex-wrap items-center gap-1.5">
                            @unless ($vendor->is_active)<span class="chip">не работаем</span>@endunless
                            @if ($c?->name)<span class="tag">{{ $c->name }}</span>@endif
                            @if ($c?->phone)<span class="tag nums">{{ $c->phoneFormatted() }}</span>@endif
                            @if ($c?->email)<span class="tag">{{ $c->email }}</span>@endif
                            @foreach ($vendor->intake_docs ?? [] as $d)@if ($doc = \App\Vendors\DocRequirement::tryFrom($d))<span class="chip">{{ $doc->label() }}</span>@endif @endforeach
                        </span>
                    </span>
                </button>
                @if ($c?->phone)<a href="tel:+{{ $c->phoneDigits() }}" class="btn btn-quiet btn-round btn-s" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
                <x-ui.sheet id="vendor-{{ $vendor->id }}" :title="$vendor->name">
                    <form method="post" action="/clients/{{ $vendor->id }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="contact_name" label="Кто ведёт хранение" :value="$c?->name"/>
                        <x-ui.field name="phone" label="Телефон" type="tel" :value="$c?->phone"/>
                        <x-ui.field name="email" label="Почта" type="email" :value="$c?->email"/>
                        <div class="field">
                            <span class="field-label">После приёма присылаем</span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($docs as $doc)
                                    <label class="choice"><input type="checkbox" switch name="intake_docs[]" value="{{ $doc->value }}" @checked(in_array($doc->value, $vendor->intake_docs ?? [], true))><span>{{ $doc->label() }}</span></label>
                                @endforeach
                            </div>
                        </div>
                        <x-ui.field name="intake_note" label="Что ещё просит" type="textarea" :value="$vendor->intake_note"/>
                        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vendor->notes"/>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                    <a href="{{ $crm }}/{{ $vendor->id }}" class="btn btn-ghost btn-block mt-3" data-turbo="false">Карточка в CRM</a>
                </x-ui.sheet>
            </div>
        @endforeach
        <div data-controller="sheet">
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Вендор</x-ui.button>
            <x-ui.sheet id="vendor-new" title="Новый вендор" :open="$errors->has('name')">
                <form method="post" action="/clients" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required autofocus/>
                    <x-ui.button block>Добавить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    </div>
</x-ui.shell>
