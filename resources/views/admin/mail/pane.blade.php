{{-- Письмо справа во «Входящих» и «Отправленных» (`MailController::pane`): ветка этого письма, как в Gmail — оно раскрыто
     (x-mail.open-letter), прежние и следующие письма ветки свёрнутыми строками над и под ним. Прочитанным письмо делает
     показ (`mail_seen_controller`), а не этот ответ: его же берёт предзагрузка соседних строк. --}}
@php
    $letters = $thread->messages->sortBy(fn ($x) => $x->date_at?->getTimestamp() ?? 0)->values();
    $continued = \App\Mail\Chains\NodeTitle::continued($letters);
    $letters = $letters->reject(fn ($x) => isset($continued[$x->id]) && $x->id !== $message->id);
    $unread = ! $message->is_seen && $message->outside;
@endphp
<x-ui.detail>
    <div class="mail-pane" data-controller="mail-seen" data-mail-seen-url-value="{{ $base }}/messages/{{ $message->id }}/seen" data-mail-seen-unread-value="{{ $unread ? 'true' : 'false' }}">
        @foreach ($letters as $letter)
            <x-mail.open-letter :message="$letter" :thread="$thread" :base="$base" :park="$park" :folded="$letter->id !== $message->id"/>
        @endforeach
    </div>
</x-ui.detail>
