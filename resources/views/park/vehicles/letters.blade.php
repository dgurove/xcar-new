{{-- Окно писем ТС: все письма всех веток одной лентой по времени (x-mail.chain), тема строкой при смене,
     этапы из цепочки кандидата, один «Ответить» внизу. В заголовке окна — «✨ Распознать», когда во входящих
     есть файлы (data-window-tools, window_controller). --}}
@php $files = $messages->contains(fn ($m) => $m->direction === \App\Mail\Direction::In && $m->attachments->isNotEmpty()); @endphp
<turbo-frame id="letters-frame" target="_top">
    @if ($files && auth()->user()->canManagePark())
        <template data-window-tools><x-mail.scan-button :url="'/cars/'.$vehicle->id.'/scan'" look="head"/></template>
    @endif
    <x-mail.chain :messages="$messages" base="/mail" :candidate="$candidate" reply :reply-open="request()->boolean('reply')"/>
</turbo-frame>
