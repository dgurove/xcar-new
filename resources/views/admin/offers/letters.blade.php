{{-- Окно писем предложения: все письма всех его веток одной лентой по времени (x-mail.chain), тема строкой при смене,
     этапы из цепочки «Из писем», один «Ответить» внизу (?reply=1 — сразу открытым). В заголовке окна —
     «✨ Распознать», когда во входящих есть файлы (data-window-tools, window_controller). --}}
@php $files = $messages->contains(fn ($m) => $m->direction === \App\Mail\Direction::In && $m->attachments->isNotEmpty()); @endphp
<turbo-frame id="letters-frame" target="_top">
    @if ($files)
        <template data-window-tools><x-mail.scan-button :url="'/offers/'.$offer->number.'/scan'" look="head"/></template>
    @endif
    <x-mail.chain :messages="$messages" :base="$base" :candidate="$candidate" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
