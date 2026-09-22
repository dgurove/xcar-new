{{-- Окно писем ТС: все письма всех веток одной лентой по времени (x-mail.chain), тема строкой при смене,
     этапы из цепочки кандидата, один «Ответить» внизу. --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$messages" base="/mail" :candidate="$candidate" :vehicle="$vehicle" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
