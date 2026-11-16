{{-- Письмо справа во «Входящих» и «Отправленных» (`MailController::pane`, 17.11.2026, владелец: «объединить в одну цепочку»):
     «Тема писем: …» и вся переписка по машине одной цепочкой, от новых к старым; открытое письмо раскрыто полностью, нажатие на
     другое раскрывает его, прежнее сворачивается. Прочитанным письмо делает показ (`mail_seen_controller`: открытое — сразу,
     другое — когда его раскрыли), а не этот ответ: его же берёт предзагрузка соседних строк. --}}
@php $unread = ! $message->is_seen && $message->outside; @endphp
<x-ui.detail>
    <div class="mail-pane" data-controller="mail-seen" data-mail-seen-url-value="{{ $base }}/messages/{{ $message->id }}/seen" data-mail-seen-base-value="{{ $base }}" data-mail-seen-unread-value="{{ $unread ? 'true' : 'false' }}">
        <div class="pane-topic">Тема писем: <span class="text-ink">{{ \App\Mail\Topic::of($case) ?? '(без темы)' }}</span></div>
        <x-mail.chain :messages="$case" :base="$base" :candidate="$thread->candidate" :focus="$message->id" context="pane" reply/>
    </div>
</x-ui.detail>
