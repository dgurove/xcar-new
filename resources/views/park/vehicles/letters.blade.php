{{-- Окно писем ТС: все письма всех веток одной цепочкой (x-mail.chain), от новых к старым, тема — в заголовке окна,
     этапы из цепочки кандидата, «Ответить» — значком у письма. --}}
<turbo-frame id="letters-frame" target="_top">
    {{-- Открыли из почты — над цепочкой ТС ссылкой в его дело (17.11.2026). --}}
    @if (request('from') === 'mail')<x-mail.case-head :vehicle="$vehicle" base="/mail" park/>@endif
    <x-mail.chain :messages="$messages" base="/mail" :candidate="$candidate" :focus="(int) request('at') ?: true" :car="false" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
