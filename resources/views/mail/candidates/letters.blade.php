{{-- Окно писем кандидата: вся цепочка лентой (x-mail.chain), этапы точками, один «Ответить» внизу. --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$c->messages" :base="$base" :candidate="$c" :vehicle="$c->vehicle" reply/>
</turbo-frame>
