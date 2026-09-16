@php $new = !$account->exists; $enc = ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'без шифрования']; @endphp
<x-ui.shell :title="$new ? 'Новый ящик' : $account->title" narrow>
    @if (session('check'))
        <div class="mb-4 flex flex-col gap-2">
            @foreach (session('check') as $kind => $r)
                <div class="box-nested flex gap-3 text-sm"><span class="shrink-0 font-medium uppercase">{{ $kind }}</span><span class="{{ $r['ok'] ? 'text-open' : 'text-danger' }}">{{ $r['message'] }}</span></div>
            @endforeach
        </div>
    @endif
    <form method="post" action="{{ $new ? '/settings/mailboxes' : '/settings/mailboxes/'.$account->slug }}" id="account-form" class="flex flex-col gap-4">
        @csrf @unless ($new) @method('put') @endunless
        <x-ui.card title="Ящик">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="title" label="Название" :value="$account->title" required/>
                <x-ui.field name="email" label="Адрес" type="email" :value="$account->email" required/>
                <x-ui.field name="from_name" label="Имя отправителя" :value="$account->from_name" placeholder="XCar"/>
                <x-ui.field name="scope" label="Чей" :options="\App\Mail\Scope::options()" :value="$account->scope?->value"/>
                <x-ui.field name="sync_from" label="Забирать письма с" type="date" :value="$account->sync_from?->toDateString()"/>
                <div class="flex items-end pb-3"><x-ui.check name="is_active" :checked="$account->is_active">Включён</x-ui.check></div>
            </div>
        </x-ui.card>
        <x-ui.card title="Приём IMAP">
            <div class="grid gap-4 sm:grid-cols-[1fr_120px_140px]">
                <x-ui.field name="imap_host" label="Сервер" :value="$account->imap_host" required/>
                <x-ui.field name="imap_port" label="Порт" inputmode="numeric" :value="$account->imap_port" required/>
                <x-ui.field name="imap_encryption" label="Шифрование" :options="$enc" :value="$account->imap_encryption"/>
                <x-ui.field name="imap_username" label="Логин" :value="$account->imap_username" autocomplete="off" required/>
                <x-ui.field name="imap_password" label="Пароль" type="password" autocomplete="new-password" :placeholder="$new ? null : '••••••••'" class="sm:col-span-2"/>
            </div>
        </x-ui.card>
        <x-ui.card title="Отправка SMTP">
            <div class="grid gap-4 sm:grid-cols-[1fr_120px_140px]">
                <x-ui.field name="smtp_host" label="Сервер" :value="$account->smtp_host" required/>
                <x-ui.field name="smtp_port" label="Порт" inputmode="numeric" :value="$account->smtp_port" required/>
                <x-ui.field name="smtp_encryption" label="Шифрование" :options="$enc" :value="$account->smtp_encryption"/>
                <x-ui.field name="smtp_username" label="Логин" :value="$account->smtp_username" autocomplete="off" required/>
                <x-ui.field name="smtp_password" label="Пароль" type="password" autocomplete="new-password" :placeholder="$new ? null : '••••••••'" class="sm:col-span-2"/>
            </div>
        </x-ui.card>
        <x-ui.card title="Подпись">
            <x-ui.editor name="signature" :value="$account->signature"/>
        </x-ui.card>
        @if (!$new && ($folders ?? collect())->isNotEmpty())
            <x-ui.card title="Папки">
                <div class="flex flex-col gap-2">
                    @foreach ($folders as $folder)
                        <label class="check"><input type="checkbox" name="folders[]" value="{{ $folder->id }}" @checked($folder->is_syncable)><span>{{ $folder->name }} <span class="tag">{{ $folder->kind->label() }}</span> <span class="tag nums">{{ $folder->messages_count }}</span></span></label>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </form>
    <x-ui.action-bar>
        <x-ui.button form="account-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @unless ($new)
            <form method="post" action="/settings/mailboxes/{{ $account->slug }}/check">@csrf<x-ui.button variant="secondary">Проверить</x-ui.button></form>
            <form method="post" action="/settings/mailboxes/{{ $account->slug }}/sync">@csrf<x-ui.button variant="secondary" aria-label="Синхронизировать"><x-ui.icon name="refresh" class="size-5"/></x-ui.button></form>
            <form method="post" action="/settings/mailboxes/{{ $account->slug }}" data-turbo-confirm="Удалить ящик вместе с письмами?">@csrf @method('delete')<x-ui.button variant="danger"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>
        @endunless
    </x-ui.action-bar>
</x-ui.shell>
