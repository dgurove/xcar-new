<x-ui.shell title="Клиенты" narrow>
    <div class="flex flex-col gap-2">
        @foreach ($clients as $client)
            @php $c = $client->contacts[0] ?? []; @endphp
            <div class="row" data-controller="sheet">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $client->name }} <span class="text-sm text-ink-muted tabular-nums">{{ $client->vehicles_count }}</span></div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        @if ($c['name'] ?? null)<span class="tag">{{ $c['name'] }}</span>@endif
                        @if ($c['phone'] ?? null)<a href="tel:{{ preg_replace('/\D+/', '', $c['phone']) }}" class="tag nums">{{ $c['phone'] }}</a>@endif
                        @if ($c['email'] ?? null)<a href="mailto:{{ $c['email'] }}" class="tag">{{ $c['email'] }}</a>@endif
                        @foreach ($client->sender_domains ?? [] as $domain)<span class="tag">{{ $domain }}</span>@endforeach
                    </div>
                </div>
                <button type="button" class="btn btn-ghost btn-s px-2" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                <x-ui.sheet id="client-{{ $client->id }}" title="Клиент">
                    <form method="post" action="/clients/{{ $client->id }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="name" label="Название" :value="$client->name" required/>
                        <x-ui.field name="contact_name" label="Контакт" :value="$c['name'] ?? null"/>
                        <x-ui.field name="phone" label="Телефон" type="tel" :value="$c['phone'] ?? null"/>
                        <x-ui.field name="email" label="Почта" type="email" :value="$c['email'] ?? null"/>
                        <x-ui.field name="sender_domains" label="Домены писем" :value="implode(', ', $client->sender_domains ?? [])" placeholder="alfastrah.ru"/>
                        <x-ui.field name="notes" label="Заметки" type="textarea" :value="$client->notes"/>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endforeach
        <div data-controller="sheet">
            <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Клиент</x-ui.button>
            <x-ui.sheet id="client-new" title="Новый клиент">
                <form method="post" action="/clients" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required autofocus/>
                    <x-ui.field name="contact_name" label="Контакт"/>
                    <x-ui.field name="phone" label="Телефон" type="tel"/>
                    <x-ui.field name="email" label="Почта" type="email"/>
                    <x-ui.field name="sender_domains" label="Домены писем" placeholder="alfastrah.ru"/>
                    <x-ui.button block>Добавить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    </div>
</x-ui.shell>
