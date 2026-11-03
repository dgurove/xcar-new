{{-- Контакт владельца из одного ответа страховой: дата и телефоны (`Offers\OwnerPhones`). Ни слова из самого письма
     (03.11.2026, владелец: письма страховой менеджер не видит ни при каких условиях). --}}
<div class="reply">
    <p class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($reply['at'], true, words: false) }}</p>
    <p class="mt-1 flex flex-wrap gap-x-4">
        @foreach ($reply['phones'] as $phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="handover-phone nums"><x-ui.icon name="phone" class="size-4"/>{{ $phone }}</a>@endforeach
    </p>
</div>
