{{-- Страховые списком; первая строка — новая (как «Новая ссылка»), вместо поля с плюсом внизу. --}}
<x-ui.cabinet title="Страховые">
    <div class="flex flex-col gap-2" data-controller="sheet">
        <button type="button" class="row w-full text-left" data-action="sheet#open">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Новая страховая</span>
        </button>
        <x-ui.sheet id="insurer-new" title="Новая страховая" :open="$errors->has('name')">
            <form method="post" action="/settings/insurers" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="name" label="Название" required autofocus/>
                <x-ui.button block>Создать</x-ui.button>
            </form>
        </x-ui.sheet>
        @foreach ($insurers as $insurer)
            <a href="/settings/insurers/{{ $insurer->id }}" class="row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="font-medium">{{ $insurer->name }}</span>@unless ($insurer->is_active)<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">выключена</x-ui.pill>@endunless</div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach (\App\Workflow\Track::cases() as $track)
                            @php $w = $insurer->workflow($track); @endphp
                            <span class="tag" @if ($w?->is_active) style="--tag-bg:#f0f7d8;--tag-text:#669709;--tag-bg-d:#1a2605;--tag-text-d:#a6cf3a" @endif>{{ $track->label() }}: {{ $w?->is_active ? 'включён'.($track === \App\Workflow\Track::Service && !$w->auto_start ? ', по кнопке' : '') : ($w ? 'выключен' : 'нет маршрута') }}</span>
                        @endforeach
                    </div>
                </div>
                <span class="text-sm text-ink-muted tabular-nums">{{ $insurer->offers_count }}</span>
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
        @endforeach
    </div>
</x-ui.cabinet>
