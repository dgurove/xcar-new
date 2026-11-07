{{-- Окно писем предложения: все письма всех его веток одной лентой по времени (x-mail.chain), тема строкой при смене,
     этапы из цепочки «Из писем», один «Ответить» внизу (?reply=1 — сразу открытым). --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$messages" :base="$base" :candidate="$candidate" :focus="(int) request('at') ?: true" reply :reply-open="request()->boolean('reply')" :reply-text="request()->string('text')->limit(2000, '')->value() ?: null"/>
</turbo-frame>
