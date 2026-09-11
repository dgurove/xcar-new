<x-ui.shell title="Страховые" back="/admin/eshchyo">
    <div class="flex flex-col gap-3">
        @foreach ($insurers as $insurer)
            <a href="/admin/strahovye/{{ $insurer->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $insurer->name }}</span>@unless ($insurer->is_active)<span class="chip bg-closed-soft text-closed">выключена</span>@endunless</div>
                    <div class="text-sm text-ink-muted">
                        @foreach (\App\Workflow\Track::cases() as $track)
                            @php $w = $insurer->workflow($track); @endphp
                            {{ $track->label() }}: {{ $w?->is_active ? 'маршрут включён' : ($w ? 'маршрут выключен' : 'нет маршрута') }}{{ $loop->last ? '' : ' · ' }}
                        @endforeach
                    </div>
                </div>
                <span class="text-sm text-ink-muted tabular-nums">{{ $insurer->offers_count }}</span>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
        <form method="post" action="/admin/strahovye" class="flex gap-2">
            @csrf
            <input name="name" class="field-input flex-1" placeholder="Новая страховая" required>
            <x-ui.button variant="secondary"><x-ui.icon name="plus" class="size-5"/></x-ui.button>
        </form>
    </div>
</x-ui.shell>
