{{-- Вендоры глазами стоянки: контакт по хранению, что прислать после приёма, заметки. Реквизиты, договор, прайс — в CRM. --}}
@php use App\Vendors\ContactRole; use App\Vendors\Kind; @endphp
<x-ui.shell title="Вендоры">
    <x-ui.toolbar :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="clients">
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Название, ИНН" enterkeyhint="search">
            <select name="kind" class="field-input field-s" aria-label="Тип">
                <option value="">Все типы</option>
                @foreach (Kind::cases() as $k)<option value="{{ $k->value }}" @selected($kind === $k)>{{ $k->plural() }}</option>@endforeach
            </select>
        </x-slot:filters>
    </x-ui.toolbar>
    @if ($vendors->isEmpty())<x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Вендоров нет' }}</x-ui.empty>@endif
    <div class="list mt-6">
        @foreach ($vendors as $vendor)
            @php $c = $vendor->contacts->firstWhere('role', ContactRole::Storage) ?? $vendor->contacts->first(); @endphp
            <div class="row" data-controller="sheet">
                <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $vendor->name }}</span>
                        <span class="row-sub">
                            @unless ($vendor->is_active)<span class="text-ink">не работаем</span>@endunless
                            {{-- Реквизиты правятся в CRM, а видеть дырку надо там, где работают: без них счёт печатается с прочерками. --}}
                            @if ($vendor->stored_count && $vendor->kind->billable() && ! \App\Billing\Party::forVendor($vendor, false)->billable())<span class="text-urgent">нет реквизитов для счёта</span>@endif
                            @if ($vendor->kind !== Kind::Insurer)<span>{{ $vendor->kind->label() }}</span>@endif
                            @if ($c?->name)<span>{{ $c->name }}</span>@endif
                            @if ($c?->phone)<span class="nums">{{ $c->phoneFormatted() }}</span>@endif
                            @if ($c?->email)<span>{{ $c->email }}</span>@endif
                        </span>
                    </span>
                    @if ($vendor->stored_count)<span class="nums shrink-0 text-ink-dim">{{ $vendor->stored_count }}</span>@endif
                </button>
                @if ($c?->phone)<a href="tel:+{{ $c->phoneDigits() }}" class="btn btn-quiet btn-round btn-s" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
                <x-ui.sheet id="vendor-{{ $vendor->id }}" :title="$vendor->name">
                    <form method="post" action="/clients/{{ $vendor->id }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="kind" label="Тип" :options="Kind::options()" :value="$vendor->kind->value"/>
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
                        <x-ui.check name="release_by_qr" :checked="$vendor->release_by_qr">Выдача по QR</x-ui.check>
                        <x-ui.field name="intake_note" label="Что ещё просит" type="textarea" :value="$vendor->intake_note"/>
                        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$vendor->notes"/>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                    @if (auth()->user()->isStaff())<a href="{{ $crm }}/{{ $vendor->id }}" class="btn btn-ghost btn-block mt-3" data-turbo="false">Карточка в CRM</a>@endif
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
    <div class="mt-3" data-controller="sheet">
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Вендор</x-ui.button>
            <x-ui.sheet id="vendor-new" title="Новый вендор" :open="$errors->has('name')">
                <form method="post" action="/clients" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required autofocus/>
                    <x-ui.field name="kind" label="Тип" :options="Kind::options()" value="insurer"/>
                    <x-ui.button block>Добавить</x-ui.button>
                </form>
            </x-ui.sheet>
    </div>
</x-ui.shell>
