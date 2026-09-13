<x-ui.swipe id="notice-{{ $item->id }}">
    <a href="/lk/uvedomleniya/{{ $item->id }}" class="box block transition-colors hover:bg-hover" data-turbo-prefetch="false">
        <div class="flex items-start justify-between gap-3">
            <p class="font-medium">{{ $item->data['title'] }}</p>
            <p class="nums shrink-0 text-sm font-normal text-ink-dim">{{ $item->created_at->translatedFormat($item->created_at->isToday() ? 'H:i' : 'j M, H:i') }}</p>
        </div>
        @if (!empty($item->data['text']))<p class="mt-1 whitespace-pre-line text-ink-muted">{{ $item->data['text'] }}</p>@endif
        @unless ($item->read_at)<span class="mt-3 inline-block rounded-full bg-accent-soft px-3 py-1 text-xs text-accent-text">Не прочитано</span>@endunless
    </a>
    <x-slot:actions>
        <form method="post" action="/lk/uvedomleniya/{{ $item->id }}/prochitano">@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $item->read_at ? 'Не прочитано' : 'Прочитано' }}"><x-ui.icon :name="$item->read_at ? 'eye-off' : 'eye'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
