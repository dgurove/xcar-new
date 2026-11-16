{{-- Письмо справа во «Входящих» и «Отправленных» (`MailController::pane`): сверху оно само (x-mail.open-letter), ниже — вся
     переписка по машине цепочкой, как в окне писем дела, от новых к старым, свёрнутой; нажатие раскрывает письмо (17.11.2026,
     владелец). «Ч.2» открытого письма в цепочке не повторяется. Прочитанным письмо делает показ (`mail_seen_controller`), а не
     этот ответ: его же берёт предзагрузка соседних строк. --}}
@php
    $sorted = $case->sortBy(fn ($x) => $x->date_at?->getTimestamp() ?? 0)->values();
    $parts = array_keys(array_filter(\App\Mail\Chains\NodeTitle::continued($sorted), fn ($head) => $head === $message->id));
    $others = $sorted->reject(fn ($x) => $x->id === $message->id || in_array($x->id, $parts, true))->values();
    $unread = ! $message->is_seen && $message->outside;
@endphp
<x-ui.detail>
    <div class="mail-pane" data-controller="mail-seen" data-mail-seen-url-value="{{ $base }}/messages/{{ $message->id }}/seen" data-mail-seen-unread-value="{{ $unread ? 'true' : 'false' }}">
        <x-mail.open-letter :message="$message" :thread="$thread" :base="$base" :park="$park"/>
        @if ($others->isNotEmpty())
            <div class="pane-case">
                <div class="pane-case-head">Тема писем: <span class="text-ink">{{ \App\Mail\Topic::of($case) ?? '(без темы)' }}</span></div>
                <x-mail.chain :messages="$others" :base="$base" :candidate="$thread->candidate" :subjects="false" :focus="false" newest closed/>
            </div>
        @endif
    </div>
</x-ui.detail>
