<x-ui.cabinet title="Уведомления" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Уведомления']]">
    <div class="mb-4 flex items-center gap-2" data-controller="sheet">
        @if ($unread)
            <form method="post" action="/lk/uvedomleniya/prochitano">@csrf<x-ui.button variant="secondary" size="s">Всё прочитано</x-ui.button></form>
        @endif
        <x-ui.button type="button" variant="secondary" size="s" class="ml-auto" data-action="sheet#open">Настройки</x-ui.button>
        <x-ui.sheet id="notification-settings" title="Уведомления">
            @if (config('xcar.vapid.public'))
            <div class="mb-4 flex flex-col gap-2" data-controller="push">
                <x-ui.button type="button" block data-push-target="on" data-action="push#enable"><x-ui.icon name="bell" class="size-5"/> Уведомления на телефон</x-ui.button>
                <x-ui.button type="button" variant="secondary" block hidden data-push-target="off" data-action="push#disable">Выключить уведомления на телефоне</x-ui.button>
                <div class="text-sm text-danger" data-push-target="state"></div>
            </div>
            @endif
            <form method="post" action="/lk/uvedomleniya/nastroyki" class="flex flex-col gap-4">
                @csrf @method('put')
                <x-ui.check name="mail" :checked="auth()->user()->wantsMail()">Дублировать на почту{{ auth()->user()->email ? ' '.auth()->user()->email : '' }}</x-ui.check>
                <x-ui.button block>Сохранить</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>

    @if ($items->isEmpty())
        <x-ui.empty>Уведомлений пока нет.</x-ui.empty>
    @else
        <div class="space-y-3">
            @foreach ($items as $item)
                <a href="/lk/uvedomleniya/{{ $item->id }}" class="box block transition-colors hover:bg-hover">
                    <div class="flex items-start justify-between gap-3">
                        <p class="font-medium">{{ $item->data['title'] }}</p>
                        <p class="nums shrink-0 text-sm font-normal text-ink-dim">{{ $item->created_at->translatedFormat($item->created_at->isToday() ? 'H:i' : 'j M, H:i') }}</p>
                    </div>
                    @if (!empty($item->data['text']))<p class="mt-1 whitespace-pre-line text-ink-muted">{{ $item->data['text'] }}</p>@endif
                    @unless ($item->read_at)<span class="mt-3 inline-block rounded-full bg-accent-soft px-3 py-1 text-xs text-accent-text">Не прочитано</span>@endunless
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $items->links() }}</div>
    @endif
</x-ui.cabinet>
