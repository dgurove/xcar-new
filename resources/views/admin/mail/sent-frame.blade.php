{{-- Экран отправки письма в окне (15.11.2026, начальник «постоянно переживает, отправлено письмо или нет»): «Отправляем…» →
     зелёная галка «Письмо ушло», когда почтовый сервер принял письмо (`send_state = sent`), или «Не ушло» с причиной и
     «Отправить снова». Узнаёт событием автору `live:mail-sent` (`SendMessage::tellAuthor`) и опросом `state` (`mail_sent_controller`);
     после «Принять» (`close`) окно закрывается само после галки. --}}
@php
    use App\Mail\AddressKind;
    $to = $message->addressesOfKind(AddressKind::To)->pluck('email')->implode(', ');
    $cc = $message->addressesOfKind(AddressKind::Cc)->pluck('email')->implode(', ');
@endphp
<turbo-frame id="letters-frame" target="_top">
    <template data-window-title>Письмо поставщику</template>
    <div class="mail-sent" data-window-compact data-controller="mail-sent" data-state="{{ $state['state'] }}"
         data-mail-sent-id-value="{{ $message->id }}" data-mail-sent-url-value="{{ $base }}/messages/{{ $message->id }}/state"
         data-mail-sent-close-value="{{ $close ? 'true' : 'false' }}">
        <svg class="mail-sent-mark" viewBox="0 0 64 64" aria-hidden="true">
            <circle class="mail-sent-track" cx="32" cy="32" r="28"/>
            <circle class="mail-sent-ring" cx="32" cy="32" r="28"/>
            <path class="mail-sent-tick" d="M20 33.5l8 8 16-17"/>
            <path class="mail-sent-bang" d="M32 19v17M32 44.5v.5"/>
        </svg>
        <p class="mail-sent-title" data-mail-sent-target="title" role="status">{{ match ($state['state']) { 'sent' => 'Письмо ушло', 'failed' => 'Не ушло', default => 'Отправляем' } }}</p>
        <p class="mail-sent-to">{{ $to }}@if ($cc)<span class="block">копия {{ $cc }}</span>@endif</p>
        <p class="mail-sent-note" data-mail-sent-target="note">{{ $state['state'] === 'sent' ? $state['at'] : ($state['state'] === 'failed' ? $state['error'] : '') }}</p>
        <div class="mail-sent-acts">
            <form method="post" action="{{ $base }}/messages/{{ $message->id }}/retry" data-turbo-frame="letters-frame" data-mail-sent-target="retry" @if ($state['state'] !== 'failed') hidden @endif>
                @csrf<button type="submit" class="btn btn-accent w-full">Отправить снова</button>
            </form>
            <button type="button" class="btn btn-quiet w-full" data-action="mail-sent#close" data-mail-sent-target="done" @if (! in_array($state['state'], ['sent', 'failed'], true)) hidden @endif>{{ $state['state'] === 'failed' ? 'Закрыть' : 'Готово' }}</button>
        </div>
    </div>
</turbo-frame>
