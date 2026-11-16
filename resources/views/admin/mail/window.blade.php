{{-- Окно ветки: одна цепочка писем (x-mail.chain), как везде (17.11.2026, владелец: «везде одинаково»). Тема — в заголовке окна,
     привязка, вендор, архив, «Не прочитано», страница ветки — в «⋯» раскрытого письма, машина — ссылкой в его шапке. --}}
<turbo-frame id="letters-frame" target="_top">
    {{-- Ответ действия из окна: тост здесь, страница под окном его не нарисует; letters-close — письмо после «Принять»,
         окно закрывается. --}}
    <x-ui.flash-stream/>
    @if (session('letters-close'))<turbo-stream action="letters-close"></turbo-stream>@endif
    @if ($errors->any())<p class="field-error mb-3">{{ $errors->first() }}</p>@endif
    <x-mail.chain :messages="$thread->messages" :base="$base" :candidate="$thread->candidate" :focus="(int) request('at') ?: true" reply/>
</turbo-frame>
