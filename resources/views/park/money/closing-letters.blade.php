{{-- Окно писем документа месяца: переписка по сверке и отправке одной цепочкой (x-mail.chain), «Ответить» — значком у письма. --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$messages" base="/mail" :focus="(int) request('at') ?: true" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
