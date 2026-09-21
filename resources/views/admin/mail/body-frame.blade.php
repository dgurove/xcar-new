{{-- Исходное письмо в ленте (фрейм body-{id}): тело как прислали, в песочнице iframe; картинки из сети — по ссылке. --}}
<turbo-frame id="body-{{ $message->id }}" target="_top">
    <div data-controller="frame" class="overflow-hidden rounded-(--radius-l) bg-white">
        <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $document }}" title="Письмо" class="block w-full" style="height:120px" data-frame-target="frame" data-action="load->frame#fit"></iframe>
    </div>
    @if (! $images && $renderer->hasRemoteImages($message))
        <a href="{{ $base }}/messages/{{ $message->id }}/body?images=1" class="btn btn-ghost btn-s mt-2" data-turbo-frame="body-{{ $message->id }}">Показать картинки</a>
    @endif
</turbo-frame>
