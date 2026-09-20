{{-- Окно писем кандидата: все письма о ТС из ящика, новые сверху, «Ответить» под письмом. --}}
<turbo-frame id="letters-frame" target="_top">
    <x-mail.panel :messages="$c->messages" :base="$base" reply/>
</turbo-frame>
