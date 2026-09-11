<x-ui.shell title="Уведомления">
    <div class="mb-4 flex items-center gap-2" data-controller="sheet">
        @if ($unread)
            <form method="post" action="/lk/uvedomleniya/prochitano">@csrf<x-ui.button variant="secondary" size="sm">Всё прочитано</x-ui.button></form>
        @endif
        <x-ui.button type="button" variant="ghost" size="sm" class="ml-auto" data-action="sheet#open">Настройки</x-ui.button>
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
        <div class="py-24 text-center text-ink-muted">Пока тихо</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($items as $item)
                <a href="/lk/uvedomleniya/{{ $item->id }}" class="row items-start">
                    @unless ($item->read_at)<span class="mt-2 size-2 shrink-0 rounded-full bg-accent"></span>@endunless
                    <div class="min-w-0 flex-1">
                        <div class="{{ $item->read_at ? 'text-ink-muted' : 'font-medium' }}">{{ $item->data['title'] }}</div>
                        @if (!empty($item->data['text']))<div class="text-sm text-ink-muted">{{ $item->data['text'] }}</div>@endif
                    </div>
                    <span class="shrink-0 text-sm text-ink-dim tabular-nums">{{ $item->created_at->translatedFormat($item->created_at->isToday() ? 'H:i' : 'j M') }}</span>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</x-ui.shell>
