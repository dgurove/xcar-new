@php
    $href = \App\Notifications\NoticeLink::for($item->data); $foreign = str_starts_with($href, 'http') || str_starts_with($href, '/admin');
    // Тревога «нет покупателя» — красной, пока причина не устранена (покупатель, новый срок — строка снова обычная).
    $alarm = ! empty($item->data['alarm']) && ! empty($item->data['offer']) && ($o = \App\Offers\OfferNumber::find((string) ($item->data['offer'] ?? ''))) && \App\Offers\Binding::panics($o);
@endphp
<x-ui.swipe id="notice-{{ $item->id }}">
    {{-- Ссылка ведёт сразу на объект; чужой хост или старый /admin — полной загрузкой. --}}
    <a href="{{ $href }}" @class(['row notice-row', 'alarm-row' => $alarm]) data-notice="{{ $item->id }}" @if ($foreign) data-turbo="false" @endif>
        @include('cabinet.notice-line', ['item' => $item, 'mini' => false, 'alarm' => $alarm])
    </a>
    <x-slot:actions>
        <form method="post" action="/account/notifications/{{ $item->id }}/read" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $item->read_at ? 'Не прочитано' : 'Прочитано' }}"><x-ui.icon :name="$item->read_at ? 'eye-off' : 'eye'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
