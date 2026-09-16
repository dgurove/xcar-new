@php $href = $item->data['href'] ?? '/account/notifications'; $foreign = str_starts_with($href, 'http') || str_starts_with($href, '/admin'); @endphp
<x-ui.swipe id="notice-{{ $item->id }}">
    {{-- Ссылка ведёт сразу на объект; чужой хост или старый /admin — полной загрузкой. --}}
    {{-- Строка: непрочитанное — с лаймовой точкой слева, время справа; текст — второй строкой. --}}
    <a href="{{ $href }}" class="row items-start" data-notice="{{ $item->id }}" @if ($foreign) data-turbo="false" @endif>
        <span class="mt-2 size-2 shrink-0 rounded-full {{ $item->read_at ? 'bg-transparent' : 'bg-accent' }}"></span>
        <span class="min-w-0 flex-1">
            <span class="flex items-baseline gap-3">
                <span class="min-w-0 flex-1 {{ $item->read_at ? '' : 'font-medium' }}">{{ $item->data['title'] }}</span>
                <span class="nums shrink-0 text-sm text-ink-dim">{{ $item->created_at->translatedFormat($item->created_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
            </span>
            @if (!empty($item->data['text']))<span class="mt-0.5 block whitespace-pre-line text-sm text-ink-muted">{{ $item->data['text'] }}</span>@endif
        </span>
    </a>
    <x-slot:actions>
        <form method="post" action="/account/notifications/{{ $item->id }}/read" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $item->read_at ? 'Не прочитано' : 'Прочитано' }}"><x-ui.icon :name="$item->read_at ? 'eye-off' : 'eye'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
