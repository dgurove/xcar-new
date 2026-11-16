{{-- Окно писем предложения: все письма всех его веток одной цепочкой (x-mail.chain), от новых к старым, тема — в заголовке окна,
     этапы из цепочки «Из писем», «Ответить» — значком у письма (?reply=1 — редактор сразу открыт). --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.chain :messages="$messages" :base="$base" :candidate="$candidate" :focus="(int) request('at') ?: true" :car="false" reply :reply-open="request()->boolean('reply')" :reply-text="request()->string('text')->limit(2000, '')->value() ?: null"/>
</turbo-frame>
