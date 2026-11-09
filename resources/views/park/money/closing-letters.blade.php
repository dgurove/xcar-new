{{-- Окно писем документа месяца: переписка по сверке и отправке одной лентой (x-mail.chain), «Ответить» внизу — в ветку. --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$messages" base="/mail" :focus="(int) request('at') ?: true" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
