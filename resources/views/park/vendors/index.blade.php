{{-- Вендоры на парковке: список плашкой, строка — в карточку вендора. Второй строкой — чего не хватает (реквизиты
     для счёта), тип, если не страховая, и контакт по хранению; справа — сколько ТС стоит. --}}
@php use App\Vendors\ContactRole; use App\Vendors\Kind; @endphp
<x-ui.shell title="Вендоры">
    <x-ui.toolbar :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="vendors">
        <x-slot:filters>
            <input type="search" name="q" value="{{ $q }}" class="field-input" placeholder="Название, ИНН" enterkeyhint="search">
            <select name="kind" class="field-input field-s" aria-label="Тип">
                <option value="">Все типы</option>
                @foreach (Kind::cases() as $k)<option value="{{ $k->value }}" @selected($kind === $k)>{{ $k->plural() }}</option>@endforeach
            </select>
        </x-slot:filters>
        @if (auth()->user()->canManagePark())
            <x-slot:actions>
                <div data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-accent btn-round" data-action="sheet#open" aria-label="Новый вендор"><x-ui.icon name="plus" class="size-5"/></button>
                    <x-ui.sheet id="vendor-new" title="Новый вендор" :open="$errors->has('name')">
                        <form method="post" action="/vendors" class="flex flex-col gap-4">
                            @csrf
                            <x-ui.field name="name" label="Название" required autofocus/>
                            <x-ui.field name="kind" label="Тип" :options="Kind::options()" value="insurer"/>
                            <x-ui.button block>Добавить</x-ui.button>
                        </form>
                    </x-ui.sheet>
                </div>
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    @if ($vendors->isEmpty())<x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Вендоров нет' }}</x-ui.empty>@endif
    @if ($vendors->isNotEmpty())
        <div class="list mt-6">
            @foreach ($vendors as $vendor)
                @php $cs = $vendor->sideContacts(false); $c = $cs->firstWhere("role", ContactRole::Storage) ?? $cs->first(); @endphp
                <a href="/vendors/{{ $vendor->id }}" class="row">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $vendor->name }}</span>
                        <span class="row-sub">
                            @unless ($vendor->is_active)<span class="text-ink">не работаем</span>@endunless
                            @if ($vendor->stored_count && $vendor->kind->billable() && ! \App\Billing\Party::forVendor($vendor, false)->billable())<span class="text-urgent">нет реквизитов для счёта</span>@endif
                            @if ($vendor->kind !== Kind::Insurer)<span>{{ $vendor->kind->label() }}</span>@endif
                            @if ($c?->name)<span>{{ $c->name }}</span>@endif
                            @if ($c?->phone)<span class="nums">{{ $c->phoneFormatted() }}</span>@endif
                        </span>
                    </span>
                    @if ($vendor->stored_count)<span class="nums shrink-0 text-ink-muted">{{ $vendor->stored_count }} ТС</span>@endif
                    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
